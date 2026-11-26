<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application\Contracts;

use Modules\WhatsApp\Domain\Inbound\InboundEvent;
use Modules\WhatsApp\Domain\Provider;

/** Verifies and normalises one provider's webhook deliveries. */
interface WebhookAdapter
{
    public function provider(): Provider;

    /**
     * @param  array<string, string>  $headers  lower-cased header names
     * @param  string|null  $numberSecret  the number's webhook secret, for providers that sign per number
     */
    public function verify(array $headers, string $rawBody, ?string $numberSecret): bool;

    /**
     * @param  array<string, mixed>  $payload
     * @return list<InboundEvent>
     */
    public function parse(array $payload): array;
}
