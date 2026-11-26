<?php

declare(strict_types=1);

namespace Modules\Inbox\Application;

use App\Support\Realtime\Realtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Contacts\Application\ContactDirectory;
use Modules\Inbox\Domain\MessagingWindow;
use Modules\WhatsApp\Application\Events\LoggedMessage;
use Modules\WhatsApp\Application\Events\MessagesLogged;
use Modules\WhatsApp\Domain\Direction;

/**
 * Keeps one conversation per customer up to date as messages are logged (#56).
 *
 * Runs inside the transaction that wrote the messages, with a fixed number of queries per batch:
 * contacts (2), conversations (1), and the free-entry window (1, only when the business sends).
 *  - a customer message opens (or reopens) the conversation and counts as unread;
 *  - a reply from the business marks the conversation read;
 *  - replies go out from the number the customer last wrote to.
 * Real-time notifications go out after commit.
 */
final class RecordConversationActivity
{
    public function __construct(private readonly ContactDirectory $contacts, private readonly Realtime $realtime) {}

    public function handle(MessagesLogged $event): void
    {
        $byPhone = $this->summarise($event->messages);

        if ($byPhone === []) {
            return;
        }

        $contactIds = $this->contacts->resolve(array_map(fn (array $s): ?string => $s['name'], $byPhone));
        $rows = $this->upsert($event->workspaceId, $byPhone, $contactIds);

        $replied = [];
        foreach ($byPhone as $phone => $s) {
            if ($s['outbound']) {
                $replied[] = (string) $rows[$contactIds[(string) $phone]]['id'];
            }
        }
        $this->openFreeEntryWindows($replied);

        // Small on purpose: clients refetch the conversation (and its messages) from the API.
        $messages = array_map(fn (array $r): array => [
            'channel' => Channels::inbox($event->workspaceId),
            'data' => [
                'type' => 'conversation.updated',
                'id' => (string) $r['id'],
                'status' => (string) $r['status'],
                'assignee_id' => $r['assignee_id'],
                'unread_count' => (int) $r['unread_count'],
                'last_message_at' => (string) $r['last_message_at'],
            ],
        ], array_values($rows));

        DB::afterCommit(fn () => $this->realtime->publish($messages));
    }

    /**
     * One entry per customer: the newest message, the unread customer messages, and the
     * latest customer message (for the window and the reply-from number).
     *
     * @param  list<LoggedMessage>  $messages
     * @return array<string, array{last: LoggedMessage, inbound: int, lastInbound: ?LoggedMessage, adAt: ?\DateTimeInterface, outbound: bool, name: ?string}>
     */
    private function summarise(array $messages): array
    {
        $out = [];

        foreach ($messages as $m) {
            $s = $out[$m->contactPhone] ?? ['last' => $m, 'inbound' => 0, 'lastInbound' => null, 'adAt' => null, 'outbound' => false, 'name' => null];

            if ($m->at >= $s['last']->at) {
                $s['last'] = $m;
            }

            if ($m->direction === Direction::Inbound) {
                $s['inbound']++;
                $s['name'] ??= $m->contactName;
                if ($s['lastInbound'] === null || $m->at >= $s['lastInbound']->at) {
                    $s['lastInbound'] = $m;
                }
                if ($m->fromAd && ($s['adAt'] === null || $m->at > $s['adAt'])) {
                    $s['adAt'] = $m->at;
                }
            } else {
                $s['outbound'] = true;
            }

            $out[$m->contactPhone] = $s;
        }

        return $out;
    }

    /**
     * @param  array<string, array{last: LoggedMessage, inbound: int, lastInbound: ?LoggedMessage, adAt: ?\DateTimeInterface, outbound: bool, name: ?string}>  $byPhone
     * @param  array<string, string>  $contactIds
     * @return array<string, array<string, mixed>> contact id => the conversation after the change
     */
    private function upsert(string $workspaceId, array $byPhone, array $contactIds): array
    {
        $values = [];
        $bindings = [];
        $ordered = [];

        foreach ($byPhone as $phone => $s) {
            $ordered[$contactIds[(string) $phone]] = $s;
        }
        ksort($ordered); // lock rows in a stable order

        foreach ($ordered as $contactId => $s) {
            $last = $s['last'];
            // a reply that is newer than every customer message marks the conversation read
            $readByReply = $last->direction === Direction::Outbound;
            $values[] = '(?::uuid, ?::uuid, ?::uuid, ?::int, ?::boolean, ?::timestamptz, ?, ?, ?, ?::timestamptz, ?::timestamptz)';
            array_push(
                $bindings,
                (string) Str::uuid7(),
                $contactId,
                ($s['lastInbound'] ?? $last)->numberId,
                $readByReply ? 0 : $s['inbound'],
                $readByReply,
                $last->at->format(DATE_ATOM),
                $last->direction->value,
                $last->type,
                $last->preview === null ? null : Str::limit($last->preview, 195),
                $s['lastInbound']?->at->format(DATE_ATOM),
                $s['adAt']?->format(DATE_ATOM),
            );
        }

        $sql = <<<'SQL'
            insert into inbox_conversations as c (
                id, workspace_id, contact_id, number_id, status, unread_count,
                last_message_at, last_message_direction, last_message_type, last_message_preview,
                last_inbound_at, ad_referral_at, created_at, updated_at
            )
            select v.id, ?::uuid, v.contact_id, v.number_id, 'open', v.unread,
                   v.last_at, v.direction, v.type, v.preview, v.inbound_at, v.ad_at, now(), now()
            from (values %s) as v (id, contact_id, number_id, unread, read_by_reply, last_at, direction, type, preview, inbound_at, ad_at)
            on conflict (workspace_id, contact_id) do update set
                -- the customer writing again reopens a closed conversation (redeliveries never get here)
                status = case when excluded.last_inbound_at is not null then 'open' else c.status end,
                closed_at = case when excluded.last_inbound_at is not null then null else c.closed_at end,
                unread_count = case
                    when excluded.last_message_direction = 'out' and excluded.last_message_at >= c.last_message_at then 0
                    else c.unread_count + excluded.unread_count
                end,
                number_id = case when excluded.last_inbound_at is not null then excluded.number_id else coalesce(c.number_id, excluded.number_id) end,
                last_message_direction = case when excluded.last_message_at >= c.last_message_at then excluded.last_message_direction else c.last_message_direction end,
                last_message_type = case when excluded.last_message_at >= c.last_message_at then excluded.last_message_type else c.last_message_type end,
                last_message_preview = case when excluded.last_message_at >= c.last_message_at then excluded.last_message_preview else c.last_message_preview end,
                last_message_at = greatest(c.last_message_at, excluded.last_message_at),
                last_inbound_at = greatest(c.last_inbound_at, excluded.last_inbound_at),
                ad_referral_at = greatest(c.ad_referral_at, excluded.ad_referral_at),
                updated_at = now()
            returning id, contact_id, status, assignee_id, unread_count, last_message_at
            SQL;

        $rows = [];

        foreach (DB::select(sprintf($sql, implode(', ', $values)), [$workspaceId, ...$bindings]) as $row) {
            /** @var array<string, mixed> $r */
            $r = (array) $row;
            $rows[(string) $r['contact_id']] = $r;
        }

        return $rows;
    }

    /**
     * Meta's free entry point: answering within 24 hours of a customer who came from an ad opens
     * 72 free hours, counted from the answer (one statement, only when the batch sends something).
     *
     * @param  list<string>  $ids  conversations the business just wrote to
     */
    private function openFreeEntryWindows(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        DB::statement(sprintf(<<<'SQL'
            update inbox_conversations set free_until = now() + interval '%d hours'
            where id = any(?::uuid[])
              and ad_referral_at is not null
              and ad_referral_at > now() - interval '%d hours'
              and (free_until is null or free_until < ad_referral_at)
            SQL, MessagingWindow::FREE_ENTRY_HOURS, MessagingWindow::HOURS), ['{'.implode(',', $ids).'}']);
    }
}
