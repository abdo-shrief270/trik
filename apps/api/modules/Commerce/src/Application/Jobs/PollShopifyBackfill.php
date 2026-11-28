<?php

declare(strict_types=1);

namespace Modules\Commerce\Application\Jobs;

use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
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
 * Step 2 of the backfill: waits for the bulk operation (10 s, backing off to 60 s, for up to 6 hours),
 * then hands the result file to ImportShopifyBackfill.
 */
final class PollShopifyBackfill implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const GIVE_UP_AFTER_POLLS = 400; // ≈ 6 hours at 60 s

    public int $tries = 5;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $storeId,
        public readonly string $operationId,
        public readonly int $polls = 0,
    ) {
        $this->onQueue((string) config('commerce.queue', 'default'));
    }

    public function handle(TenantContext $context, ShopifyAdminClient $client): void
    {
        $context->run($this->workspaceId, function () use ($client): void {
            $store = Store::query()->find($this->storeId);
            if ($store === null || $store->status !== StoreStatus::Active || $store->access_token === null
                || ($store->backfill['operation'] ?? null) !== $this->operationId) {
                return; // disconnected, or a newer backfill replaced this one
            }

            try {
                $data = $client->graphql(ShopDomain::fromShopify($store->shop_domain), $store->access_token, ShopifyQueries::BULK_STATUS, ['id' => $this->operationId], 2);
            } catch (ShopifyCallFailed $e) {
                StoreHealth::afterFailure($store, $e, $this);

                return;
            }

            $status = (string) data_get($data, 'node.status', '');

            if (in_array($status, ['CREATED', 'RUNNING', 'CANCELING'], true)) {
                if ($this->polls >= self::GIVE_UP_AFTER_POLLS) {
                    $this->finish($store, 'failed', 'Shopify took too long to prepare the orders.');

                    return;
                }
                self::dispatch($this->workspaceId, $this->storeId, $this->operationId, $this->polls + 1)
                    ->delay(now()->addSeconds(min(60, 10 + 5 * $this->polls)));

                return;
            }

            if ($status === 'COMPLETED') {
                $url = data_get($data, 'node.url');
                if (! is_string($url) || $url === '') {
                    $this->finish($store, 'done'); // no orders in the window

                    return;
                }
                $store->update(['backfill' => [...$store->backfill, 'status' => 'importing', 'expected' => (int) data_get($data, 'node.objectCount', 0)]]);
                ImportShopifyBackfill::dispatch($this->workspaceId, $this->storeId, $this->operationId, $url);

                return;
            }

            $this->finish($store, 'failed', 'Shopify could not export the orders ('.strtolower($status.' '.(string) data_get($data, 'node.errorCode', '')).').');
        });
    }

    private function finish(Store $store, string $status, ?string $error = null): void
    {
        $store->update(['backfill' => [...$store->backfill, 'status' => $status, 'error' => $error, 'finished_at' => now()->toIso8601String()]]);
    }
}
