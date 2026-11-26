<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure\Providers;

use Modules\WhatsApp\Domain\Template;

final class TemplateMapper
{
    /** @param array<mixed> $row */
    public static function fromCloudApi(array $row): Template
    {
        $components = $row['components'] ?? [];

        return new Template(
            name: (string) ($row['name'] ?? ''),
            language: (string) ($row['language'] ?? ''),
            status: strtolower((string) ($row['status'] ?? 'unknown')),
            category: strtolower((string) ($row['category'] ?? '')),
            components: is_array($components) ? array_values(array_filter($components, 'is_array')) : [],
            id: isset($row['id']) && is_scalar($row['id']) ? (string) $row['id'] : null,
        );
    }
}
