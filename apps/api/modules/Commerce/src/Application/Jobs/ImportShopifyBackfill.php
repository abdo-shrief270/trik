<?php

declare(strict_types=1);

namespace Modules\Commerce\Application\Jobs;

use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Modules\Commerce\Application\ImportStoreOrder;
use Modules\Commerce\Application\Shopify\ShopifyOrderMapper;
use Modules\Commerce\Application\Shopify\UnmappableOrder;
use Modules\Commerce\Domain\StoreStatus;
use Modules\Commerce\Infrastructure\Models\Store;
use SplFileObject;
use Throwable;

/**
 * Step 3 of the backfill: streams the bulk result (JSONL) to a temp file and imports it order by order.
 *
 * Line items come as their own lines pointing at their order (__parentId). Orders are held in a small
 * buffer until their items are in; an item whose order was already flushed (Shopify doesn't promise
 * that children follow their parent directly) sends that order to RefreshShopifyOrder instead, so no
 * order is ever imported with items missing. Re-running is safe: imports are idempotent and versioned.
 */
final class ImportShopifyBackfill implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Orders held while their line items arrive. */
    public const BUFFER = 200;

    public int $tries = 3;

    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $storeId,
        public readonly string $operationId,
        public readonly string $url,
    ) {
        $this->onQueue((string) config('commerce.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'commerce-backfill-import:'.$this->storeId;
    }

    public function handle(TenantContext $context, ShopifyOrderMapper $mapper, ImportStoreOrder $import): void
    {
        // Shopify's signed storage URL; nothing else is ever downloaded here
        $host = (string) parse_url($this->url, PHP_URL_HOST);
        if (parse_url($this->url, PHP_URL_SCHEME) !== 'https' || ! preg_match('/(^|\.)(googleapis\.com|shopifycloud\.com|shopify\.com)$/', $host)) {
            report(new \RuntimeException("Refusing a bulk result from {$host}"));

            return;
        }

        $file = tempnam(sys_get_temp_dir(), 'shopify-bulk-');
        if ($file === false) {
            throw new \RuntimeException('No temp file for the backfill.');
        }

        try {
            Http::timeout(600)->sink($file)->get($this->url)->throw();

            $context->run($this->workspaceId, function () use ($file, $mapper, $import): void {
                $store = Store::query()->find($this->storeId);
                if ($store === null || $store->status !== StoreStatus::Active || ($store->backfill['operation'] ?? null) !== $this->operationId) {
                    return;
                }

                $counts = ['recorded' => 0, 'stale' => 0, 'skipped' => 0];
                /** @var array<string, array{node: array<string, mixed>, items: list<array<string, mixed>>}> $pending */
                $pending = [];
                $flushed = [];
                $late = [];

                $flush = function (int $keep) use (&$pending, &$flushed, &$counts, $mapper, $import, $store): void {
                    while (count($pending) > $keep) {
                        $gid = array_key_first($pending);
                        if ($gid === null) {
                            break;
                        }
                        $order = $pending[$gid];
                        unset($pending[$gid]);
                        $flushed[$gid] = true;

                        try {
                            $counts[$import->handle($store->id, $mapper->fromGraphql($order['node'], $order['items']))]++;
                        } catch (UnmappableOrder) {
                            $counts['skipped']++;
                        }

                        if (array_sum($counts) % 200 === 0) {
                            $store->update(['backfill' => [...$store->backfill, 'count' => $counts['recorded'], 'skipped' => $counts['skipped']]]);
                        }
                    }
                };

                $lines = new SplFileObject($file);
                while (! $lines->eof()) {
                    $line = trim((string) $lines->fgets());
                    if ($line === '') {
                        continue;
                    }
                    $row = json_decode($line, true);
                    if (! is_array($row)) {
                        continue;
                    }

                    $parent = $row['__parentId'] ?? null;
                    if (is_string($parent)) {
                        if (isset($pending[$parent])) {
                            $pending[$parent]['items'][] = $row;
                        } elseif (isset($flushed[$parent])) {
                            $late[$parent] = true;
                        }

                        continue;
                    }

                    if (is_string($row['id'] ?? null)) {
                        $pending[$row['id']] = ['node' => $row, 'items' => []];
                        if (count($pending) > self::BUFFER) {
                            $flush(intdiv(self::BUFFER, 2));
                        }
                    }
                }
                $flush(0);

                foreach (array_keys($late) as $gid) {
                    if (preg_match('#/(\d+)$#', $gid, $m) === 1) {
                        RefreshShopifyOrder::dispatch($this->workspaceId, $this->storeId, $m[1]);
                    }
                }

                $store->update(['backfill' => [...$store->backfill, 'status' => 'done', 'count' => $counts['recorded'], 'skipped' => $counts['skipped'],
                    'stale' => $counts['stale'], 'finished_at' => now()->toIso8601String(), 'error' => null]]);
            });
        } catch (Throwable $e) {
            if ($this->attempts() >= $this->tries) {
                $context->run($this->workspaceId, function (): void {
                    $store = Store::query()->find($this->storeId);
                    $store?->update(['backfill' => [...$store->backfill, 'status' => 'failed', 'error' => 'The import stopped; try again from the store settings.', 'finished_at' => now()->toIso8601String()]]);
                });
            }

            throw $e;
        } finally {
            @unlink($file);
        }
    }
}
