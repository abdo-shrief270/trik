<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Domain;

/** A message template as the provider reports it. Components keep the Cloud API shape. */
final readonly class Template
{
    /** @param list<array<string, mixed>> $components */
    public function __construct(
        public string $name,
        public string $language,
        public string $status,
        public string $category,
        public array $components = [],
        public ?string $id = null,
    ) {}
}
