<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Modules\WhatsApp\Application\Exceptions\ProviderRequestFailed;
use Modules\WhatsApp\Application\ProviderRegistry;
use Modules\WhatsApp\Domain\Messages\TemplateMessage;
use Modules\WhatsApp\Domain\MessageStatus;
use Modules\WhatsApp\Domain\PhoneNumber;
use Modules\WhatsApp\Infrastructure\Models\BroadcastSend;
use Modules\WhatsApp\Infrastructure\Models\WhatsAppNumber;

/**
 * Sends up to SendBroadcast::CHUNK queued broadcast messages from one number, within the
 * number's messages-per-second limit. When the limit is hit, or the provider throttles, the
 * job comes back a second later and continues with what is still queued. Only queued rows are
 * sent, so a retried or duplicated job never sends twice.
 */
final class SendBroadcastChunk implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const MAX_SEND_ATTEMPTS = 5;

    /** @param list<string> $sendIds */
    public function __construct(
        public readonly string $numberId,
        public readonly TemplateMessage $template,
        public readonly array $sendIds,
    ) {
        $this->onQueue((string) config('whatsapp.queue', 'default'));
    }

    /** Broadcasts can take a while at the number's rate; keep retrying for up to 12 hours. */
    public function retryUntil(): Carbon
    {
        return now()->addHours(12);
    }

    public function handle(ProviderRegistry $providers): void
    {
        $lock = Cache::lock('whatsapp-broadcast-chunk:'.md5(implode(',', $this->sendIds)), 300);

        if (! $lock->get()) {
            $this->release(30); // the same chunk is running elsewhere

            return;
        }

        try {
            $this->sendChunk($providers);
        } finally {
            $lock->release();
        }
    }

    private function sendChunk(ProviderRegistry $providers): void
    {
        $number = WhatsAppNumber::query()->find($this->numberId);

        if ($number === null) {
            return;
        }

        $adapter = $providers->for($number->toConnected());
        $limit = max(1, (int) config('whatsapp.default_messages_per_second', 80));
        $key = 'whatsapp-mps:'.$number->id;

        $pending = BroadcastSend::query()
            ->whereIn('id', $this->sendIds)
            ->where('status', MessageStatus::Queued)
            ->orderBy('id')
            ->get();

        foreach ($pending as $send) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $this->release(1);

                return;
            }

            RateLimiter::hit($key, 1);
            $send->increment('attempts');

            try {
                $result = $adapter->send(PhoneNumber::fromString($send->contact_phone), $this->template);
            } catch (ProviderRequestFailed $e) {
                $permanent = ! $e->retryable || $send->attempts >= self::MAX_SEND_ATTEMPTS;
                $send->forceFill([
                    'error' => $e->toArray(),
                    'status' => $permanent ? MessageStatus::Failed : MessageStatus::Queued,
                    'failed_at' => $permanent ? now() : null,
                ])->save();

                if (! $permanent) {
                    $this->release(min(60, 2 ** $send->attempts));

                    return;
                }

                continue;
            }

            $send->forceFill([
                'provider_message_id' => $result->messageId,
                'status' => MessageStatus::Sent,
                'sent_at' => now(),
                'error' => null,
            ])->save();
        }
    }
}
