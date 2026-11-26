<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application;

use Modules\WhatsApp\Application\Jobs\ProcessWebhookEvent;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Infrastructure\Models\WebhookEvent;

/**
 * The fast path of a webhook: store the raw delivery, queue the processing, answer.
 * Must run inside the number's workspace.
 */
final class AcceptWebhook
{
    /** @param array<string, mixed> $payload */
    public function handle(string $numberId, Provider $provider, array $payload): WebhookEvent
    {
        $event = WebhookEvent::query()->create([
            'number_id' => $numberId,
            'provider' => $provider,
            'payload' => $payload,
        ]);

        ProcessWebhookEvent::dispatch($event->id);

        return $event;
    }
}
