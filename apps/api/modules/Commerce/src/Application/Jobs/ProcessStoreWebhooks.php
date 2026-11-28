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
use Illuminate\Support\Str;
use Modules\Commerce\Application\Shopify\ShopifyWebhookHandler;
use Modules\Commerce\Domain\Platform;
use Modules\Commerce\Domain\StoreStatus;
use Modules\Commerce\Infrastructure\Models\Store;
use Modules\Commerce\Infrastructure\Models\StoreWebhookEvent;
use Throwable;

/**
 * Drains a workspace's pending store deliveries, oldest first, one transaction each: one bad payload
 * never blocks the rest. Rows are claimed with SKIP LOCKED, so two workers never take the same one.
 * A failing delivery is retried with backoff and parked after MAX_ATTEMPTS (kept, with its error).
 */
final class ProcessStoreWebhooks implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const MAX_ATTEMPTS = 5;

    /** Deliveries handled per run before handing the worker back (the job re-queues itself). */
    public const PER_RUN = 500;

    public int $tries = 10;

    public int $uniqueFor = 120;

    public function __construct(public readonly string $workspaceId)
    {
        $this->onQueue((string) config('commerce.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'commerce-webhooks:'.$this->workspaceId;
    }

    /** EasyOrders deliveries are only a hint which order changed: it is read back with the store's key. */
    private static function easyOrders(StoreWebhookEvent $event, Store $store): string
    {
        if ($store->status !== StoreStatus::Active) {
            return 'ignored:store-inactive';
        }
        $orderId = $event->payload['order_id'] ?? $event->payload['id'] ?? null;
        if (! is_string($orderId) || ! Str::isUuid($orderId)) {
            return 'skipped:no-order';
        }
        RefreshEasyOrdersOrder::dispatch($store->workspace_id, $store->id, strtolower($orderId))->afterCommit();

        return 'refresh-queued';
    }

    public function handle(ShopifyWebhookHandler $shopify, TenantContext $context): void
    {
        $context->run($this->workspaceId, function () use ($shopify): void {
            $failed = [];
            $done = 0;

            while ($done < self::PER_RUN) {
                $outcome = DB::transaction(function () use ($shopify, &$failed): ?string {
                    /** @var StoreWebhookEvent|null $event */
                    $event = StoreWebhookEvent::query()
                        ->whereNull('processed_at')
                        ->where('attempts', '<', self::MAX_ATTEMPTS)
                        ->when($failed !== [], fn ($q) => $q->whereNotIn('id', $failed))
                        ->orderBy('created_at')
                        ->lock('for update skip locked')
                        ->first();

                    if ($event === null) {
                        return null;
                    }

                    try {
                        $outcome = DB::transaction(function () use ($shopify, $event): string {
                            $store = Store::query()->find($event->store_id);

                            return match (true) {
                                $store === null => 'ignored:store-gone',
                                $store->platform === Platform::EasyOrders => self::easyOrders($event, $store),
                                default => $shopify->handle($event, $store),
                            };
                        });
                        $event->update(['processed_at' => now(), 'outcome' => Str::limit($outcome, 40, ''), 'attempts' => $event->attempts + 1, 'last_error' => null]);
                    } catch (Throwable $e) {
                        $failed[] = $event->id;
                        $event->update(['attempts' => $event->attempts + 1, 'last_error' => Str::limit($e->getMessage(), 1000)]);
                        report($e);
                    }

                    return 'next';
                });

                if ($outcome === null) {
                    break;
                }
                $done++;
            }

            if ($done >= self::PER_RUN) {
                self::dispatch($this->workspaceId); // more waiting: continue in a fresh job
            } elseif ($failed !== []) {
                $this->release(min(600, 30 * max(1, $this->attempts()))); // try the failed ones later
            }
        });
    }
}
