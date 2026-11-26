<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application\Jobs;

use App\Support\Events\EventPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\WhatsApp\Application\Events\MessageStatusChanged;
use Modules\WhatsApp\Application\Exceptions\ProviderRequestFailed;
use Modules\WhatsApp\Application\ProviderRegistry;
use Modules\WhatsApp\Domain\Messages\OutgoingMessage;
use Modules\WhatsApp\Domain\MessageStatus;
use Modules\WhatsApp\Domain\PhoneNumber;
use Modules\WhatsApp\Infrastructure\Models\Message;
use Modules\WhatsApp\Infrastructure\Models\WhatsAppNumber;
use Throwable;

/** Sends one queued message. Retries throttling and outages with backoff; gives up on permanent errors. */
final class SendOutgoingMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    public function __construct(
        public readonly string $messageId,
        public readonly PhoneNumber $to,
        public readonly OutgoingMessage $message,
        public readonly ?string $replyTo = null,
    ) {
        $this->onQueue((string) config('whatsapp.queue', 'default'));
    }

    /** @return list<int> seconds between attempts */
    public function backoff(): array
    {
        return [10, 30, 120, 600, 1800];
    }

    public function handle(ProviderRegistry $providers, EventPublisher $events): void
    {
        $row = Message::query()->find($this->messageId);

        if ($row === null || $row->status !== MessageStatus::Queued) {
            return; // already sent or cancelled: never send twice
        }

        $number = WhatsAppNumber::query()->findOrFail($row->number_id);
        $row->increment('attempts');

        try {
            $result = $providers->for($number->toConnected())->send($this->to, $this->message, $this->replyTo);
        } catch (ProviderRequestFailed $e) {
            $row->forceFill(['error' => $e->toArray()])->save();

            if (! $e->retryable) {
                $this->markFailed($row, $events);
                $this->fail($e);

                return;
            }

            throw $e;
        }

        $row->forceFill([
            'provider_message_id' => $result->messageId,
            'status' => MessageStatus::Sent,
            'sent_at' => now(),
            'error' => null,
        ])->save();

        $events->publish(new MessageStatusChanged($row->workspace_id, $row->number_id, $row->id, $result->messageId, MessageStatus::Sent));
    }

    /** Called by the queue after the last attempt. */
    public function failed(?Throwable $e): void
    {
        $row = Message::query()->find($this->messageId);

        if ($row !== null && $row->status === MessageStatus::Queued) {
            $this->markFailed($row, app(EventPublisher::class));
        }
    }

    private function markFailed(Message $row, EventPublisher $events): void
    {
        $row->forceFill(['status' => MessageStatus::Failed, 'failed_at' => now()])->save();
        $events->publish(new MessageStatusChanged($row->workspace_id, $row->number_id, $row->id, (string) $row->provider_message_id, MessageStatus::Failed));
    }
}
