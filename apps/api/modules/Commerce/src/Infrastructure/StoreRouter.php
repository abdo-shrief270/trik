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

    /**
     * Every store record of a shop, any status and workspace (privacy requests).
     *
     * @return list<array{workspace_id: string, store_id: string, status: string}>
     */
    public function history(Platform $platform, string $shopDomain): array
    {
        $stores = [];
        foreach (DB::select('select workspace_id, store_id, status from commerce_store_history(?, ?)', [$platform->value, $shopDomain]) as $row) {
            $r = get_object_vars($row);
            $stores[] = ['workspace_id' => (string) $r['workspace_id'], 'store_id' => (string) $r['store_id'], 'status' => (string) $r['status']];
        }

        return $stores;
    }
}
