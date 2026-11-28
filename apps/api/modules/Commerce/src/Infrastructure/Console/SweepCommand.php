<?php

declare(strict_types=1);

namespace Modules\Commerce\Infrastructure\Console;

use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Application\Jobs\ProcessStoreWebhooks;

/**
 * Every minute (owner connection, ids only, across workspaces):
 *  - wakes the drain job for workspaces whose store deliveries waited over a minute (a lost queue push
 *    or a Redis restart never strands a stored webhook);
 *  - once an hour, deletes processed deliveries older than the retention.
 */
final class SweepCommand extends Command
{
    protected $signature = 'commerce:sweep';

    protected $description = 'Recover stranded store webhooks and prune old ones';

    public function handle(TenantContext $context): int
    {
        $owner = DB::connection('owner');

        $workspaces = $owner->table('commerce_webhook_events')
            ->whereNull('processed_at')
            ->where('attempts', '<', ProcessStoreWebhooks::MAX_ATTEMPTS)
            ->where('created_at', '<', now()->subMinute())
            ->where('created_at', '>', now()->subDays(7))
            ->distinct()
            ->pluck('workspace_id');

        foreach ($workspaces as $workspaceId) {
            $context->run((string) $workspaceId, fn () => ProcessStoreWebhooks::dispatch((string) $workspaceId));
        }

        $pruned = 0;
        if (now()->minute === 7) {
            $pruned = $owner->table('commerce_webhook_events')
                ->whereNotNull('processed_at')
                ->where('processed_at', '<', now()->subDays((int) config('commerce.webhook_retention_days', 30)))
                ->limit(50000)
                ->delete();
        }

        $this->info("Woke {$workspaces->count()} workspace(s); pruned {$pruned} delivery(ies).");

        return self::SUCCESS;
    }
}
