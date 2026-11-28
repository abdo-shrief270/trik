<?php

declare(strict_types=1);

namespace Modules\Commerce\Application\Jobs;

use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Modules\Addresses\Domain\Address;
use Modules\Commerce\Application\StoreCallFailed;
use Modules\Commerce\Domain\StoreStatus;
use Modules\Commerce\Infrastructure\EasyOrders\EasyOrdersClient;
use Modules\Commerce\Infrastructure\Models\Store;
use Modules\Orders\Application\OrderReader;
use Modules\Orders\Application\OrderTimeline;
use Modules\Orders\Domain\OrderStatus;

/**
 * Tells EasyOrders what happened to one of its orders in Trik, from the order's state when the job runs:
 *  - status:  confirmed → "confirmed", cancelled → "canceled" (write_status);
 *  - address: a private order note with the fixed address (write_address): EasyOrders' API can't edit it.
 * Each write lands on the order timeline. The status webhook EasyOrders then sends is a store change,
 * which is never written back.
 */
final class WriteBackEasyOrdersOrder implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 10;

    public int $uniqueFor = 300;

    /** @param 'status'|'address' $what */
    public function __construct(
        public readonly string $workspaceId,
        public readonly string $orderId,
        public readonly string $what,
    ) {
        $this->onQueue((string) config('commerce.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return "commerce-easyorders-writeback:{$this->orderId}:{$this->what}";
    }

    public function handle(TenantContext $context, OrderReader $orders, EasyOrdersClient $client): void
    {
        $context->run($this->workspaceId, function () use ($orders, $client): void {
            $order = $orders->find($this->orderId);
            if ($order === null || $order->source !== 'easyorders' || $order->externalId === null) {
                return;
            }

            $storeId = DB::table('commerce_order_versions')->where('external_id', $order->externalId)->value('store_id');
            $store = is_string($storeId) ? Store::query()->find($storeId) : null;
            if ($store === null || $store->status !== StoreStatus::Active || $store->access_token === null) {
                return;
            }

            try {
                $done = null;
                if ($this->what === 'status' && $store->setting('write_status')) {
                    $status = match ($order->status) {
                        OrderStatus::Confirmed => 'confirmed',
                        OrderStatus::Cancelled => 'canceled',
                        default => null,
                    };
                    if ($status !== null) {
                        $client->updateStatus($store, $order->externalId, $status);
                        $done = 'status:'.$status;
                    }
                }

                $address = Address::fromArray($order->address);
                if ($this->what === 'address' && $store->setting('write_address') && $order->addressFixed && $address->isDeliverable()) {
                    $client->addNote($store, $order->externalId, 'تريك: العنوان اتصلّح ← '.$address->line());
                    $done = 'address-note';
                }
            } catch (StoreCallFailed $e) {
                if ($e->retryAfter === null && ! $e->unauthorized) {
                    OrderTimeline::add($order->id, 'store_update_failed', null, ['store' => 'easyorders', 'what' => $this->what, 'error' => mb_substr($e->getMessage(), 0, 300)]);
                    report($e);

                    return;
                }
                StoreHealth::afterFailure($store, $e, $this);

                return;
            }

            if ($done !== null) {
                OrderTimeline::add($order->id, 'store_updated', null, ['store' => 'easyorders', 'what' => [$done]]);
            }
        });
    }
}
