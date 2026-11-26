<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application;

use Modules\WhatsApp\Application\Jobs\ProcessWebhookEvents;
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

        // At most one pending drain job per workspace; it picks this delivery up with any others.
        ProcessWebhookEvents::dispatch($event->workspace_id);

        return $event;
    }
}
