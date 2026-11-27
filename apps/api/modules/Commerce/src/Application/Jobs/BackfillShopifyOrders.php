<?php

declare(strict_types=1);

namespace Modules\Commerce\Application\Jobs;

use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Commerce\Application\Shopify\ShopifyCallFailed;
use Modules\Commerce\Application\Shopify\ShopifyQueries;
use Modules\Commerce\Domain\Shopify\ShopDomain;
use Modules\Commerce\Domain\StoreStatus;
use Modules\Commerce\Infrastructure\Models\Store;
use Modules\Commerce\Infrastructure\Shopify\ShopifyAdminClient;

/**
 * Step 1 of the backfill: starts a GraphQL bulk operation over the orders of the last 90 days (60 when
 * Shopify hasn't granted read_all_orders: it serves no more), then hands over to PollShopifyBackfill.
 * Old orders never trigger a confirmation message (see ConfirmationEngine, max_order_age_hours).
 */
final class BackfillShopifyOrders implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 10;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $workspaceId, public readonly string $storeId)
    {
        $this->onQueue((string) config('commerce.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'commerce-backfill:'.$this->storeId;
    }

    public function handle(TenantContext $context, ShopifyAdminClient $client): void
    {
        $context->run($this->workspaceId, function () use ($client): void {
            $store = Store::query()->find($this->storeId);
            if ($store === null || $store->status !== StoreStatus::Active || $store->access_token === null) {
                return;
            }
            $shop = ShopDomain::fromShopify($store->shop_domain);

            $days = (int) config('commerce.shopify.backfill_days', 90);
            if (! in_array('read_all_orders', $store->grantedScopes(), true)) {
                $days = min($days, 60);
            }
            $since = now()->subDays($days)->startOfDay();

            try {
                $name = data_get($client->graphql($shop, $store->access_token, ShopifyQueries::SHOP, [], 2), 'shop.name');
                if (is_string($name) && $name !== '') {
                    $store->update(['name' => mb_substr($name, 0, 255)]);
                }

                $run = $client->graphql($shop, $store->access_token, ShopifyQueries::BULK_RUN, ['query' => ShopifyQueries::bulkOrders($since)], 10);
            } catch (ShopifyCallFailed $e) {
                StoreHealth::afterFailure($store, $e, $this);

                return;
            }

            $errors = (array) data_get($run, 'bulkOperationRunQuery.userErrors', []);
            $operation = data_get($run, 'bulkOperationRunQuery.bulkOperation.id');

            if ($errors !== [] || ! is_string($operation)) {
                $message = implode('; ', array_map(fn (mixed $e): string => (string) data_get($e, 'message', 'error'), $errors));
                if (str_contains(strtolower($message), 'already in progress')) {
                    $this->release(120); // another bulk query of ours on this shop: wait for it

                    return;
                }
                $store->update(['backfill' => ['status' => 'failed', 'error' => mb_substr($message ?: 'no operation', 0, 300), 'finished_at' => now()->toIso8601String()]]);

                return;
            }

            $store->update(['backfill' => [
                'status' => 'running', 'operation' => $operation, 'since' => $since->toIso8601String(),
                'started_at' => now()->toIso8601String(), 'count' => 0, 'skipped' => 0,
            ]]);

            PollShopifyBackfill::dispatch($this->workspaceId, $this->storeId, $operation)->delay(now()->addSeconds(10));
        });
    }
}
