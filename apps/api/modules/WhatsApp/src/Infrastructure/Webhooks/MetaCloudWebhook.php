<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure\Webhooks;

use Modules\WhatsApp\Application\Contracts\WebhookAdapter;
use Modules\WhatsApp\Domain\Provider;

/** One app-level webhook for all numbers, signed with the Meta app secret (X-Hub-Signature-256). */
final class MetaCloudWebhook implements WebhookAdapter
{
    public function __construct(private readonly CloudApiPayloadParser $parser) {}

    public function provider(): Provider
    {
        return Provider::MetaCloud;
    }

    public function verify(array $headers, string $rawBody, ?string $numberSecret): bool
    {
        $secret = (string) config('whatsapp.providers.meta_cloud.app_secret', '');
        $signature = $headers['x-hub-signature-256'] ?? '';

        return $secret !== '' && $signature !== '' && hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    public function parse(array $payload): array
    {
        return $this->parser->parse($payload);
    }
}
