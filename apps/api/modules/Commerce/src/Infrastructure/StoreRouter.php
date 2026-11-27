<?php

declare(strict_types=1);

namespace Modules\Commerce\Infrastructure;

use Illuminate\Support\Facades\DB;
use Modules\Commerce\Domain\Platform;

/**
 * Finds the workspace a shop is connected to before any workspace is active (webhooks, installs),
 * through the narrow SECURITY DEFINER commerce_store_route(): ids only, active connections only.
 */
final class StoreRouter
{
    /** @return array{workspace_id: string, store_id: string}|null */
    public function byShop(Platform $platform, string $shopDomain): ?array
    {
        $row = DB::selectOne('select workspace_id, store_id from commerce_store_route(?, ?)', [$platform->value, $shopDomain]);

        return $row === null ? null : ['workspace_id' => (string) $row->workspace_id, 'store_id' => (string) $row->store_id];
    }
}
