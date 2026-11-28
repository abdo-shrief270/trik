<?php

declare(strict_types=1);

namespace Modules\Commerce\Application\Jobs;

use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Commerce\Application\EasyOrders\EasyOrdersOrderMapper;
use Modules\Commerce\Application\ImportStoreOrder;
use Modules\Commerce\Application\Shopify\UnmappableOrder;
use Modules\Commerce\Application\StoreCallFailed;
use Modules\Commerce\Domain\StoreStatus;
use Modules\Commerce\Infrastructure\EasyOrders\EasyOrdersClient;
use Modules\Commerce\Infrastructure\Models\Store;

/**
 * Reads one order from EasyOrders with the store's own key and imports it. Every EasyOrders webhook comes
 * here: its body is only a hint (EasyOrders doesn't sign webhooks), so Trik never imports a payload it
 * didn't read back itself. Several webhooks for one order collapse into one read while queued.
 */
final class RefreshEasyOrdersOrder implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 10;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $storeId,
        public readonly string $orderId,
    ) {
        $this->onQueue((string) config('commerce.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return "commerce-easyorders-refresh:{$this->storeId}:{$this->orderId}";
    }

    public function handle(TenantContext $context, EasyOrdersClient $client, EasyOrdersOrderMapper $mapper, ImportStoreOrder $import): void
    {
        $context->run($this->workspaceId, function () use ($client, $mapper, $import): void {
            $store = Store::query()->find($this->storeId);
            if ($store === null || $store->status !== StoreStatus::Active || $store->access_token === null) {
                return;
            }

            try {
                $order = $client->order($store, $this->orderId);
            } catch (StoreCallFailed $e) {
                StoreHealth::afterFailure($store, $e, $this);

                return;
            }

            if ($order === null || strtolower((string) ($order['store_id'] ?? $store->shop_domain)) !== $store->shop_domain) {
                return; // gone, or not this store's order
            }

            try {
                $import->handle($store->id, $mapper->fromApi($order));
            } catch (UnmappableOrder) {
                // no phone or no items: nothing Trik can confirm
            }
        });
    }
}
