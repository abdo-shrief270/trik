<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\WhatsApp\Application\Events\BroadcastProgressed;
use Modules\WhatsApp\Application\Events\MessageReceived;
use Modules\WhatsApp\Application\Events\MessageStatusChanged;
use Modules\WhatsApp\Domain\Direction;
use Modules\WhatsApp\Domain\Inbound\InboundMessage;
use Modules\WhatsApp\Domain\Inbound\StatusUpdate;
use Modules\WhatsApp\Domain\MessageStatus;
use Modules\WhatsApp\Infrastructure\Models\WebhookEvent;

/**
 * Applies a batch of webhook deliveries with a fixed number of queries, whatever the batch size:
 *  - inbound messages: one de-duplication insert + one message insert
 *  - statuses: reduced to one row per message, then one UPDATE … FROM (VALUES …) per table
 * Must run inside the workspace and inside a transaction. Returns the events to publish
 * after commit.
 */
final class WebhookBatchProcessor
{
    /** Status webhooks for messages older than this are ignored (keeps partition pruning). */
    public const STATUS_WINDOW_DAYS = 30;

    public function __construct(private readonly ProviderRegistry $providers) {}

    /**
     * @param  list<WebhookEvent>  $events
     * @return list<object>
     */
    public function process(array $events, string $workspaceId): array
    {
        $inbound = [];
        $statuses = [];

        foreach ($events as $event) {
            foreach ($this->providers->webhooks($event->provider)->parse($event->payload) as $item) {
                if ($item instanceof InboundMessage) {
                    $inbound[$event->number_id.'|'.$item->messageId] = [$event->number_id, $item];
                } elseif ($item instanceof StatusUpdate) {
                    $statuses[] = [$event->number_id, $item];
                }
            }
        }

        return [
            ...$this->recordInbound(array_values($inbound), $workspaceId),
            ...$this->applyStatuses($statuses, $workspaceId),
        ];
    }

    /**
     * @param  list<array{0: string, 1: InboundMessage}>  $items
     * @return list<MessageReceived>
     */
    private function recordInbound(array $items, string $workspaceId): array
    {
        if ($items === []) {
            return [];
        }

        $now = now();
        $keys = [];
        $bindings = [];

        foreach ($items as [$numberId, $m]) {
            $keys[] = '(?::uuid, ?, ?::uuid, ?::timestamptz)';
            array_push($bindings, $numberId, $m->messageId, $workspaceId, $now);
        }

        // Only ids seen for the first time come back: redeliveries stop here.
        $fresh = DB::select(
            'insert into whatsapp_inbound_keys (number_id, provider_message_id, workspace_id, created_at) values '
            .implode(', ', $keys).' on conflict do nothing returning number_id, provider_message_id',
            $bindings,
        );

        $isFresh = [];
        foreach ($fresh as $row) {
            $isFresh[$row->number_id.'|'.$row->provider_message_id] = true;
        }

        $rows = [];
        $events = [];

        foreach ($items as [$numberId, $m]) {
            if (! isset($isFresh[$numberId.'|'.$m->messageId])) {
                continue;
            }

            $id = (string) Str::uuid7();
            $rows[] = [
                'id' => $id,
                'workspace_id' => $workspaceId,
                'number_id' => $numberId,
                'direction' => Direction::Inbound->value,
                'provider_message_id' => $m->messageId,
                'contact_phone' => $m->from,
                'type' => $m->type,
                'body' => json_encode(array_filter([
                    'text' => $m->text,
                    'contact_name' => $m->contactName,
                    'media_id' => $m->mediaId,
                    'mime_type' => $m->mimeType,
                    'reply_id' => $m->replyId,
                    'context_message_id' => $m->contextMessageId,
                    'raw' => $m->raw,
                ], fn ($v) => $v !== null), JSON_THROW_ON_ERROR),
                'status' => MessageStatus::Received->value,
                'received_at' => $m->timestamp > 0 ? Carbon::createFromTimestamp($m->timestamp) : $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $events[] = new MessageReceived($workspaceId, $numberId, $id, $m);
        }

        if ($rows !== []) {
            DB::table('whatsapp_messages')->insert($rows);
        }

        return $events;
    }

    /**
     * @param  list<array{0: string, 1: StatusUpdate}>  $items
     * @return list<object>
     */
    private function applyStatuses(array $items, string $workspaceId): array
    {
        if ($items === []) {
            return [];
        }

        // One row per message: the earliest time seen for each stage, and any errors.
        $reduced = [];
        foreach ($items as [$numberId, $s]) {
            $key = $numberId.'|'.$s->messageId;
            $reduced[$key] ??= ['number_id' => $numberId, 'id' => $s->messageId, 'sent' => null, 'delivered' => null, 'read' => null, 'failed' => null, 'errors' => null];
            $stage = match ($s->status) {
                MessageStatus::Sent => 'sent',
                MessageStatus::Delivered => 'delivered',
                MessageStatus::Read => 'read',
                default => 'failed',
            };
            $at = Carbon::createFromTimestamp($s->timestamp > 0 ? $s->timestamp : time())->toIso8601String();
            $reduced[$key][$stage] = $reduced[$key][$stage] === null ? $at : min($reduced[$key][$stage], $at);

            if ($s->errors !== []) {
                $reduced[$key]['errors'] = json_encode(['provider_errors' => $s->errors], JSON_THROW_ON_ERROR);
            }
        }

        $values = [];
        $bindings = [];
        foreach ($reduced as $r) {
            $values[] = '(?::uuid, ?, ?::timestamptz, ?::timestamptz, ?::timestamptz, ?::timestamptz, ?::jsonb)';
            array_push($bindings, $r['number_id'], $r['id'], $r['sent'], $r['delivered'], $r['read'], $r['failed'], $r['errors']);
        }

        $events = [];

        foreach ($this->update('whatsapp_messages', $values, $bindings, "and t.direction = 'out'", 't.id') as $row) {
            $events[] = new MessageStatusChanged($row['workspace_id'], $row['number_id'], $row['id'], $row['provider_message_id'], MessageStatus::from($row['new_status']));
        }

        $progress = [];
        foreach ($this->update('whatsapp_broadcast_sends', $values, $bindings, '', 't.broadcast_id as id') as $row) {
            $progress[$row['id']][$row['new_status']] = ($progress[$row['id']][$row['new_status']] ?? 0) + 1;
        }

        foreach ($progress as $broadcastId => $changes) {
            $events[] = new BroadcastProgressed($workspaceId, (string) $broadcastId, $changes);
        }

        return $events;
    }

    /**
     * Moves statuses forward in one statement. Mirrors MessageStatus::canMoveTo():
     * failed and received are final, read beats failed, nothing moves backwards.
     * Returns only rows whose status actually changed (RETURNING old/new, PostgreSQL 18+).
     *
     * @param  list<string>  $values
     * @param  list<mixed>  $bindings
     * @return list<array{id: string, workspace_id: string, number_id: string, provider_message_id: string, old_status: string, new_status: string}>
     */
    private function update(string $table, array $values, array $bindings, string $extraWhere, string $idColumn): array
    {
        $sql = <<<SQL
            with v (number_id, provider_message_id, sent_at, delivered_at, read_at, failed_at, errors) as (values %s)
            update {$table} t set
                status = case
                    when t.status in ('failed', 'received', 'read') then t.status
                    when v.read_at is not null then 'read'
                    when v.failed_at is not null then 'failed'
                    when v.delivered_at is not null then 'delivered'
                    when v.sent_at is not null and t.status = 'queued' then 'sent'
                    else t.status
                end,
                sent_at = coalesce(t.sent_at, v.sent_at, v.delivered_at, v.read_at),
                delivered_at = coalesce(t.delivered_at, v.delivered_at, v.read_at),
                read_at = coalesce(t.read_at, v.read_at),
                failed_at = case when t.status in ('read', 'failed') or v.read_at is not null then t.failed_at else coalesce(t.failed_at, v.failed_at) end,
                error = coalesce(v.errors, t.error),
                updated_at = now()
            from v
            where t.number_id = v.number_id
              and t.provider_message_id = v.provider_message_id
              and t.provider_message_id is not null
              and t.created_at >= now() - interval '%d days'
              {$extraWhere}
            returning {$idColumn}, t.workspace_id, t.number_id, t.provider_message_id, old.status as old_status, new.status as new_status
            SQL;

        $changed = [];

        foreach (DB::select(sprintf($sql, implode(', ', $values), self::STATUS_WINDOW_DAYS), $bindings) as $row) {
            $r = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', (array) $row);

            if ($r['old_status'] !== $r['new_status']) {
                $changed[] = [
                    'id' => $r['id'], 'workspace_id' => $r['workspace_id'], 'number_id' => $r['number_id'],
                    'provider_message_id' => $r['provider_message_id'], 'old_status' => $r['old_status'], 'new_status' => $r['new_status'],
                ];
            }
        }

        return $changed;
    }
}
