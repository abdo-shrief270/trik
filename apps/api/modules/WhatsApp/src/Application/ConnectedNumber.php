<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application;

use Modules\WhatsApp\Domain\Provider;

/** What an adapter needs to talk to the provider for one number. */
final readonly class ConnectedNumber
{
    /** @param array<string, string> $credentials */
    public function __construct(
        public string $id,
        public Provider $provider,
        public string $externalId,
        public ?string $wabaId,
        public array $credentials,
    ) {}

    public function credential(string $key): string
    {
        $value = $this->credentials[$key] ?? '';

        if ($value === '') {
            throw new \InvalidArgumentException("Missing {$key} for number {$this->id}.");
        }

        return $value;
    }
}
