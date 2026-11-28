<?php

declare(strict_types=1);

namespace Modules\Commerce\Application\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Commerce\Application\StoreCallFailed;
use Modules\Commerce\Domain\StoreStatus;
use Modules\Commerce\Infrastructure\Models\Store;

/** What a store job does when a Shopify call fails (see StoreCallFailed). */
final class StoreHealth
{
    /** @param ShouldQueue $job a queued job using InteractsWithQueue (it can release itself) */
    public static function afterFailure(Store $store, StoreCallFailed $e, ShouldQueue $job): void
    {
        if ($e->unauthorized) {
            // the seller removed the app (the app/uninstalled webhook may still be on its way)
            $store->update(['status' => StoreStatus::Uninstalled, 'access_token' => null, 'uninstalled_at' => now()]);

            return;
        }

        if ($e->retryAfter !== null && method_exists($job, 'release')) {
            $job->release($e->retryAfter);

            return;
        }

        throw $e; // permanent: reported and failed
    }
}
