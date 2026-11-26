<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application\Jobs;

use App\Support\Events\EventPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\WhatsApp\Application\Events\MessageReceived;
use Modules\WhatsApp\Application\Events\MessageStatusChanged;
use Modules\WhatsApp\Application\ProviderRegistry;
use Modules\WhatsApp\Domain\Direction;
use Modules\WhatsApp\Domain\Inbound\InboundMessage;
use Modules\WhatsApp\Domain\Inbound\StatusUpdate;
use Modules\WhatsApp\Domain\MessageStatus;
use Modules\WhatsApp\Infrastructure\Models\Message;
use Modules\WhatsApp\Infrastructure\Models\WebhookEvent;
use Throwable;

/**
 * Turns a stored webhook delivery into message rows and events. Safe to run twice:
 * inbound messages are unique per provider id and statuses only move forward.
 */
final class ProcessWebhookEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 600];

    public function __construct(public readonly string $eventId)
    {
        $this->onQueue((string) config('whatsapp.queue', 'default'));
    }

    public function handle(ProviderRegistry $providers, EventPublisher $events): void
    {
        $event = WebhookEvent::query()->find($this->eventId);

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $event->increment('attempts');
        $published = [];

        try {
            DB::transaction(function () use ($event, $providers, &$published): void {
                foreach ($providers->webhooks($event->provider)->parse($event->payload) as $item) {
                    $published[] = match (true) {
                        $item instanceof InboundMessage => $this->recordInbound($event, $item),
                        $item instanceof StatusUpdate => $this->applyStatus($item, $event->number_id),
                        default => null,
                    };
                }

                $event->forceFill(['processed_at' => now(), 'last_error' => null])->save();
            });
        } catch (Throwable $e) {
            $event->forceFill(['last_error' => Str::limit($e->getMessage(), 1000)])->save();

            throw $e;
        }

        foreach (array_filter($published) as $domainEvent) {
            $events->publish($domainEvent);
        }
    }

    private function recordInbound(WebhookEvent $event, InboundMessage $m): ?MessageReceived
    {
        $id = (string) Str::uuid7();
        $at = $m->timestamp > 0 ? Carbon::createFromTimestamp($m->timestamp) : now();

        $inserted = Message::query()->insertOrIgnore([
            'id' => $id,
            'workspace_id' => $event->workspace_id,
            'number_id' => $event->number_id,
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
            'received_at' => $at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $inserted === 1 ? new MessageReceived($event->workspace_id, $event->number_id, $id, $m) : null;
    }

    private function applyStatus(StatusUpdate $s, string $numberId): ?MessageStatusChanged
    {
        $row = Message::query()
            ->where('number_id', $numberId)
            ->where('provider_message_id', $s->messageId)
            ->where('direction', Direction::Outbound->value)
            ->lockForUpdate()
            ->first();

        if ($row === null || ! $row->status->canMoveTo($s->status)) {
            return null;
        }

        $at = $s->timestamp > 0 ? Carbon::createFromTimestamp($s->timestamp) : now();
        $column = match ($s->status) {
            MessageStatus::Sent => 'sent_at',
            MessageStatus::Delivered => 'delivered_at',
            MessageStatus::Read => 'read_at',
            default => 'failed_at',
        };

        $row->forceFill(array_filter([
            'status' => $s->status,
            $column => $at,
            // a read receipt implies delivery, even if the delivered webhook never came
            'delivered_at' => $s->status === MessageStatus::Read && $row->delivered_at === null ? $at : null,
            'error' => $s->errors !== [] ? ['provider_errors' => $s->errors] : null,
        ], fn ($v) => $v !== null))->save();

        return new MessageStatusChanged($row->workspace_id, $row->number_id, $row->id, $s->messageId, $s->status);
    }
}
