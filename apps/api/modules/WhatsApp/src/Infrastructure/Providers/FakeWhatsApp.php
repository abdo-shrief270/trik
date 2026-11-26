<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure\Providers;

use Modules\WhatsApp\Application\Exceptions\ProviderRequestFailed;
use Modules\WhatsApp\Domain\Messages\OutgoingMessage;
use Modules\WhatsApp\Domain\PhoneNumber;
use Modules\WhatsApp\Domain\Template;

/** State behind FakeProvider: what was sent, which templates exist, queued failures. */
final class FakeWhatsApp
{
    /** @var list<array{number: string, to: PhoneNumber, message: OutgoingMessage, id: string}> */
    public array $sent = [];

    /** @var list<string> */
    public array $read = [];

    /** @var array<string, Template> */
    public array $templates = [];

    /** @var array<string, array{url: string, secret: string}> */
    public array $webhooks = [];

    /** @var list<ProviderRequestFailed> */
    private array $failures = [];

    public function failNext(ProviderRequestFailed $failure): void
    {
        $this->failures[] = $failure;
    }

    public function takeFailure(): ?ProviderRequestFailed
    {
        return array_shift($this->failures);
    }
}
