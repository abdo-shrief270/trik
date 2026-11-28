<?php

declare(strict_types=1);

namespace Modules\Commerce\Infrastructure\Shopify;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Modules\Commerce\Application\Shopify\ShopifyCallFailed;

/**
 * Shopify's GraphQL Admin API limits each shop by query cost (a leaky bucket: e.g. 2,000 points that
 * refill at 100 per second). Every response reports the bucket (extensions.cost.throttleStatus); this
 * keeps the latest report per shop in the cache, shared by all workers, and before a call estimates
 * what's left. Short waits are slept; longer ones become a "retry in N seconds" for the job, so a
 * worker is never parked on one shop. An estimate, not a lock: Shopify still enforces the real limit,
 * and a THROTTLED answer goes through the same path.
 */
final class CostThrottle
{
    /** Longest wait slept inside a call; anything longer releases the job. */
    public const MAX_SLEEP_SECONDS = 2.0;

    public function reserve(string $shop, int $cost): void
    {
        $state = Cache::get($this->key($shop));
        if (! is_array($state)) {
            return; // nothing known yet: let the first call tell us
        }

        $restore = max(1.0, (float) $state['restore']);
        $available = min((float) $state['max'], (float) $state['available'] + (microtime(true) - (float) $state['at']) * $restore);

        if ($available >= $cost) {
            Cache::put($this->key($shop), [...$state, 'available' => $available - $cost, 'at' => microtime(true)], 300);

            return;
        }

        $wait = ($cost - $available) / $restore;
        if ($wait > self::MAX_SLEEP_SECONDS) {
            throw ShopifyCallFailed::throttled((int) ceil($wait));
        }

        Sleep::for((int) ceil($wait * 1000))->milliseconds();
    }

    /** @param array<string, mixed> $throttleStatus maximumAvailable, currentlyAvailable, restoreRate */
    public function record(string $shop, array $throttleStatus): void
    {
        if (! isset($throttleStatus['currentlyAvailable'], $throttleStatus['maximumAvailable'], $throttleStatus['restoreRate'])) {
            return;
        }

        Cache::put($this->key($shop), [
            'available' => (float) $throttleStatus['currentlyAvailable'],
            'max' => (float) $throttleStatus['maximumAvailable'],
            'restore' => (float) $throttleStatus['restoreRate'],
            'at' => microtime(true),
        ], 300);
    }

    /** Seconds until $cost points are back, from the latest report. */
    public function secondsUntil(string $shop, int $cost): int
    {
        $state = Cache::get($this->key($shop));
        if (! is_array($state)) {
            return 2;
        }

        return max(1, (int) ceil(($cost - (float) $state['available']) / max(1.0, (float) $state['restore'])));
    }

    private function key(string $shop): string
    {
        return 'commerce:shopify:cost:'.$shop;
    }
}
