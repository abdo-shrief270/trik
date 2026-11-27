<?php

declare(strict_types=1);

namespace Modules\Commerce\Application;

use App\Support\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Application\Shopify\MappedOrder;
use Modules\Orders\Application\RecordOrder;

/**
 * Hands a store order to RecordOrder unless Trik already applied a newer version of it.
 *
 * Shopify doesn't promise delivery order, retries for up to 48 hours, and the backfill reads orders
 * that webhooks may have updated since. Each order's last applied `updated_at` is kept; anything not
 * newer is "stale" and dropped, so an old payload never rolls an order's items, totals or tracking back.
 * Must run inside the store's workspace.
 */
final class ImportStoreOrder
{
    public function __construct(
        private readonly RecordOrder $record,
        private readonly TenantContext $tenant,
    ) {}

    /** @return 'recorded'|'stale' */
    public function handle(string $storeId, MappedOrder $order): string
    {
        return DB::transaction(function () use ($storeId, $order): string {
            // one writer per order (two deliveries of the same order on two workers)
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['commerce-order:'.$storeId.':'.$order->externalId]);

            $applied = DB::table('commerce_order_versions')
                ->where('store_id', $storeId)
                ->where('external_id', $order->externalId)
                ->value('source_updated_at');

            if (is_string($applied) && new DateTimeImmutable($applied) >= $order->updatedAt) {
                return 'stale';
            }

            $this->record->handle($order->input);

            DB::table('commerce_order_versions')->upsert([[
                'workspace_id' => $this->tenant->require(),
                'store_id' => $storeId,
                'external_id' => $order->externalId,
                'source_updated_at' => $order->updatedAt->format('Y-m-d H:i:s.uP'),
            ]], ['store_id', 'external_id'], ['source_updated_at']);

            return 'recorded';
        });
    }
}
