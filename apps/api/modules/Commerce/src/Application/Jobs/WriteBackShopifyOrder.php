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
use Modules\Commerce\Application\Shopify\ShopifyCallFailed;
use Modules\Commerce\Application\Shopify\ShopifyQueries;
use Modules\Commerce\Domain\Shopify\ShopDomain;
use Modules\Commerce\Domain\StoreStatus;
use Modules\Commerce\Infrastructure\Models\Store;
use Modules\Commerce\Infrastructure\Shopify\ShopifyAdminClient;
use Modules\Orders\Application\OrderReader;
use Modules\Orders\Application\OrderSnapshot;
use Modules\Orders\Application\OrderTimeline;
use Modules\Orders\Domain\OrderStatus;

/**
 * Tells Shopify what happened to one of its orders in Trik (#116, #344), from the order's state when the job
 * runs, so a burst of changes collapses into one write of the latest state:
 *  - status:  confirmed → tag trik-confirmed; cancelled → tag trik-cancelled, and (if the seller opted in)
 *             cancel the order in Shopify, restocking it, without a refund or an email to the customer;
 *  - address: a fixed address replaces the shipping address, keeping the name, phone and postcode Shopify has.
 * Each write lands on the order timeline (store_updated / store_update_failed). Shopify's resulting
 * orders/updated webhook comes back as a store change, which never triggers a write (no loop).
 */
final class WriteBackShopifyOrder implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const TAG_CONFIRMED = 'trik-confirmed';

    public const TAG_CANCELLED = 'trik-cancelled';

    public int $tries = 8;

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
        return "commerce-writeback:{$this->orderId}:{$this->what}";
    }

    public function handle(TenantContext $context, OrderReader $orders, ShopifyAdminClient $client): void
    {
        $context->run($this->workspaceId, function () use ($orders, $client): void {
            $order = $orders->find($this->orderId);
            if ($order === null || $order->source !== 'shopify' || $order->externalId === null) {
                return;
            }

            $storeId = DB::table('commerce_order_versions')->where('external_id', $order->externalId)->value('store_id');
            $store = is_string($storeId) ? Store::query()->find($storeId) : null;
            if ($store === null || $store->status !== StoreStatus::Active || $store->access_token === null) {
                return; // disconnected: nothing to tell
            }

            $shop = ShopDomain::fromShopify($store->shop_domain);
            $gid = 'gid://shopify/Order/'.$order->externalId;

            try {
                $done = $this->what === 'address'
                    ? $this->address($client, $shop, $store, $gid, $order)
                    : $this->status($client, $shop, $store, $gid, $order);
            } catch (ShopifyCallFailed $e) {
                if ($e->retryAfter === null && ! $e->unauthorized) {
                    // Shopify refused it (bad input, missing scope): retrying won't help. Shown on the order, reported.
                    OrderTimeline::add($order->id, 'store_update_failed', null, ['store' => 'shopify', 'what' => $this->what, 'error' => mb_substr($e->getMessage(), 0, 300)]);
                    report($e);

                    return;
                }
                StoreHealth::afterFailure($store, $e, $this); // throttled / unavailable: later; token revoked: uninstalled

                return;
            }

            if ($done !== []) {
                OrderTimeline::add($order->id, 'store_updated', null, ['store' => 'shopify', 'what' => $done]);
            }
        });
    }

    /** @return list<string> what was written */
    private function status(ShopifyAdminClient $client, ShopDomain $shop, Store $store, string $gid, OrderSnapshot $order): array
    {
        $tags = match ($order->status) {
            OrderStatus::Confirmed, OrderStatus::ReadyToShip => [self::TAG_CONFIRMED, self::TAG_CANCELLED],
            OrderStatus::Cancelled => [self::TAG_CANCELLED, self::TAG_CONFIRMED],
            default => null,
        };
        if ($tags === null) {
            return [];
        }

        $done = [];
        $token = (string) $store->access_token;

        if ($store->setting('tag_orders')) {
            $this->userErrors($client->graphql($shop, $token, ShopifyQueries::TAGS_ADD, ['id' => $gid, 'tags' => [$tags[0]]], 10), 'tagsAdd');
            $this->userErrors($client->graphql($shop, $token, ShopifyQueries::TAGS_REMOVE, ['id' => $gid, 'tags' => [$tags[1]]], 10), 'tagsRemove');
            $done[] = 'tag:'.$tags[0];
        }

        if ($order->status === OrderStatus::Cancelled && $store->setting('cancel_in_store')) {
            $current = $client->graphql($shop, $token, ShopifyQueries::ORDER_SHIPPING_ADDRESS, ['id' => $gid], 5);
            if (data_get($current, 'order.cancelledAt') === null) {
                $result = $client->graphql($shop, $token, ShopifyQueries::ORDER_CANCEL, ['orderId' => $gid, 'staffNote' => 'Cancelled in Trik'], 10);
                $this->userErrors($result, 'orderCancel', 'orderCancelUserErrors');
                $done[] = 'cancelled';
            }
        }

        return $done;
    }

    /** @return list<string> */
    private function address(ShopifyAdminClient $client, ShopDomain $shop, Store $store, string $gid, OrderSnapshot $order): array
    {
        $address = Address::fromArray($order->address);
        if (! $store->setting('write_address') || ! $order->addressFixed || ! $address->isDeliverable()) {
            return [];
        }

        $token = (string) $store->access_token;
        $current = (array) data_get($client->graphql($shop, $token, ShopifyQueries::ORDER_SHIPPING_ADDRESS, ['id' => $gid], 5), 'order.shippingAddress', []);

        $line1 = implode('، ', array_filter([$address->street, $address->building === null ? null : 'عمارة '.$address->building]));
        $line2 = implode('، ', array_filter([
            $address->floor === null ? null : 'الدور '.$address->floor,
            $address->apartment === null ? null : 'شقة '.$address->apartment,
            $address->landmark,
        ]));

        $input = array_filter([
            // kept from Shopify: orderUpdate replaces the whole address
            'firstName' => $current['firstName'] ?? null,
            'lastName' => $current['lastName'] ?? null,
            'phone' => $current['phone'] ?? null,
            'zip' => $current['zip'] ?? null,
            'company' => $current['company'] ?? null,
            // from Trik
            'address1' => mb_substr($line1, 0, 255),
            'address2' => $line2 === '' ? null : mb_substr($line2, 0, 255),
            'city' => $address->area,
            'provinceCode' => $address->governorate?->value,
            'countryCode' => 'EG',
        ], fn (mixed $v): bool => $v !== null && $v !== '');

        $this->userErrors($client->graphql($shop, $token, ShopifyQueries::ORDER_UPDATE, ['input' => ['id' => $gid, 'shippingAddress' => $input]], 10), 'orderUpdate');

        return ['address'];
    }

    /** @param array<string, mixed> $data */
    private function userErrors(array $data, string $field, string $errorsKey = 'userErrors'): void
    {
        $errors = (array) data_get($data, "{$field}.{$errorsKey}", []);
        if ($errors !== []) {
            $messages = array_map(fn (mixed $e): string => (string) data_get($e, 'message', 'error'), $errors);

            throw new ShopifyCallFailed("Shopify refused {$field}: ".mb_substr(implode('; ', $messages), 0, 300));
        }
    }
}
