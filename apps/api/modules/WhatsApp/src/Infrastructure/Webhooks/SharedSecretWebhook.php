<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure\Webhooks;

use Modules\WhatsApp\Application\Contracts\WebhookAdapter;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Infrastructure\Providers\Dialog360Provider;

/**
 * Per-number webhooks authenticated by a secret header that Trik sets when it registers
 * the webhook URL (360dialog does not sign deliveries). Also used by the fake provider.
 */
abstract class SharedSecretWebhook implements WebhookAdapter
{
    public function __construct(private readonly CloudApiPayloadParser $parser) {}

    public function verify(array $headers, string $rawBody, ?string $numberSecret): bool
    {
        $sent = $headers[strtolower(Dialog360Provider::WEBHOOK_SECRET_HEADER)] ?? '';

        return $numberSecret !== null && $numberSecret !== '' && $sent !== '' && hash_equals($numberSecret, $sent);
    }

    public function parse(array $payload): array
    {
        return $this->parser->parse($payload);
    }
}
