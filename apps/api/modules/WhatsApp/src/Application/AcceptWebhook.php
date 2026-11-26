<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application;

use Modules\WhatsApp\Application\Jobs\ProcessWebhookEvents;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Infrastructure\Models\WebhookEvent;
use Throwable;

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

        // At most one pending drain job per workspace; it picks this delivery up with any others.
        // The delivery is already stored: if the queue is down, answer 200 anyway and let
        // `whatsapp:sweep` (every minute) wake the drain job later. Nothing is lost.
        try {
            ProcessWebhookEvents::dispatch($event->workspace_id);
        } catch (Throwable $e) {
            report($e);
        }

        return $event;
    }
}
