<?php

declare(strict_types=1);

namespace Modules\Commerce\Application\Shopify;

use Modules\Commerce\Application\ImportStoreOrder;
use Modules\Commerce\Application\Jobs\RefreshShopifyOrder;
use Modules\Commerce\Domain\StoreStatus;
use Modules\Commerce\Infrastructure\Models\Store;
use Modules\Commerce\Infrastructure\Models\StoreWebhookEvent;

/**
 * What a Shopify delivery does, by topic. Returns the outcome kept on the delivery:
 *  - orders/create|updated|cancelled  the order is recorded, or "stale" (a newer version was applied),
 *                                     or "skipped:<reason>" (no phone, no items);
 *  - fulfillments/create|update       the order is re-read through GraphQL (the payload is a fulfillment,
 *                                     not the order), queued as its own job;
 *  - app/uninstalled                  the store is marked uninstalled and its token dropped.
 */
final class ShopifyWebhookHandler
{
    public const ORDER_TOPICS = ['orders/create', 'orders/updated', 'orders/cancelled'];

    public const FULFILLMENT_TOPICS = ['fulfillments/create', 'fulfillments/update'];

    public const TOPICS = [...self::ORDER_TOPICS, ...self::FULFILLMENT_TOPICS, 'app/uninstalled'];

    public function __construct(
        private readonly ShopifyOrderMapper $mapper,
        private readonly ImportStoreOrder $import,
    ) {}

    public function handle(StoreWebhookEvent $event, Store $store): string
    {
        if ($event->topic === 'app/uninstalled') {
            if ($store->status === StoreStatus::Active) {
                $store->update(['status' => StoreStatus::Uninstalled, 'access_token' => null, 'uninstalled_at' => now()]);
            }

            return 'uninstalled';
        }

        if ($store->status !== StoreStatus::Active) {
            return 'ignored:store-inactive'; // a late delivery after a disconnect
        }

        if (in_array($event->topic, self::ORDER_TOPICS, true)) {
            try {
                return $this->import->handle($store->id, $this->mapper->fromWebhook($event->payload));
            } catch (UnmappableOrder $e) {
                return 'skipped:'.$e->reason;
            }
        }

        if (in_array($event->topic, self::FULFILLMENT_TOPICS, true)) {
            $orderId = $event->payload['order_id'] ?? null;
            if (! is_int($orderId) && ! (is_string($orderId) && ctype_digit($orderId))) {
                return 'skipped:no-order';
            }
            RefreshShopifyOrder::dispatch($store->workspace_id, $store->id, (string) $orderId)->afterCommit();

            return 'refresh-queued';
        }

        return 'ignored:topic';
    }
}
