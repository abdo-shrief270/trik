<?php

declare(strict_types=1);

namespace Modules\Commerce\Application;

use Modules\Commerce\Infrastructure\Models\Store;

/** A store as the API shows it: never the token, never the bulk operation id. */
final class StorePresenter
{
    /** @return array<string, mixed> */
    public function present(Store $store): array
    {
        $b = $store->backfill;

        return [
            'id' => $store->id,
            'platform' => $store->platform->value,
            'shop_domain' => $store->shop_domain,
            'name' => $store->name,
            'status' => $store->status->value,
            'scopes' => $store->grantedScopes(),
            'installed_at' => $store->installed_at?->toIso8601String(),
            'uninstalled_at' => $store->uninstalled_at?->toIso8601String(),
            'last_webhook_at' => $store->last_webhook_at?->toIso8601String(),
            'settings' => $store->effectiveSettings(),
            'backfill' => [
                'status' => $b['status'] ?? 'none',
                'since' => $b['since'] ?? null,
                'imported' => (int) ($b['count'] ?? 0),
                'skipped' => (int) ($b['skipped'] ?? 0),
                'started_at' => $b['started_at'] ?? null,
                'finished_at' => $b['finished_at'] ?? null,
                'error' => $b['error'] ?? null,
            ],
        ];
    }
}
