<?php

declare(strict_types=1);

namespace Modules\Commerce\Application\Jobs;

use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Commerce\Application\ImportStoreOrder;
use Modules\Commerce\Application\Shopify\ShopifyCallFailed;
use Modules\Commerce\Application\Shopify\ShopifyOrderMapper;
use Modules\Commerce\Application\Shopify\ShopifyQueries;
use Modules\Commerce\Application\Shopify\UnmappableOrder;
use Modules\Commerce\Domain\Shopify\ShopDomain;
use Modules\Commerce\Domain\StoreStatus;
use Modules\Commerce\Infrastructure\Models\Store;
use Modules\Commerce\Infrastructure\Shopify\ShopifyAdminClient;

/**
 * Re-reads one order from Shopify (GraphQL) and imports it: after a fulfillment, whose webhook carries
 * the fulfillment rather than the order. Several fulfillment events for one order collapse into one
 * read (unique per order while queued); the version check drops it if a newer order webhook won.
 */
final class RefreshShopifyOrder implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 8;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $storeId,
        public readonly string $orderId,
    ) {
        $this->onQueue((string) config('commerce.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return "commerce-refresh:{$this->storeId}:{$this->orderId}";
    }

    public function handle(TenantContext $context, ShopifyAdminClient $client, ShopifyOrderMapper $mapper, ImportStoreOrder $import): void
    {
        $context->run($this->workspaceId, function () use ($client, $mapper, $import): void {
            $store = Store::query()->find($this->storeId);
            if ($store === null || $store->status !== StoreStatus::Active || $store->access_token === null) {
                return;
            }

            try {
                $data = $client->graphql(ShopDomain::fromShopify($store->shop_domain), $store->access_token, ShopifyQueries::order(), ['id' => 'gid://shopify/Order/'.$this->orderId], 20);
            } catch (ShopifyCallFailed $e) {
                StoreHealth::afterFailure($store, $e, $this);

                return;
            }

            $node = $data['order'] ?? null;
            if (! is_array($node)) {
                return; // deleted in Shopify meanwhile
            }

            try {
                $import->handle($store->id, $mapper->fromGraphql($node));
            } catch (UnmappableOrder) {
                // same rule as the webhook: nothing to record
            }
        });
    }
}
