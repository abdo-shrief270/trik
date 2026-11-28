<?php

declare(strict_types=1);

namespace Modules\Analytics\Infrastructure\Console;

use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Analytics\Application\PilotReport;

/**
 * Before vs after for every pilot seller in one CSV (the weekly pilot review). A pilot is a workspace with a
 * go-live date set, or where Trik has confirmed an order. Lists workspaces on the owner connection (ids
 * only), then computes each inside its own workspace.
 */
final class PilotReportCommand extends Command
{
    protected $signature = 'analytics:pilot-report {--out= : Where to write the CSV (default: storage/app/pilot-report-<date>.csv)}';

    protected $description = 'Before vs after Trik for every pilot seller, as one CSV';

    public function handle(TenantContext $context, PilotReport $report): int
    {
        $owner = DB::connection('owner');
        $ids = $owner->table('analytics_pilots')->whereNotNull('live_at')->pluck('workspace_id')
            ->merge($owner->table('confirmation_runs')->distinct()->pluck('workspace_id'))
            ->map(fn (mixed $id): string => (string) $id)->unique()->values();

        $path = (string) ($this->option('out') ?: storage_path('app/pilot-report-'.now()->format('Y-m-d').'.csv'));
        $out = fopen($path, 'w');
        if ($out === false) {
            $this->error("Cannot write {$path}");

            return self::FAILURE;
        }

        fputcsv($out, ['workspace_id', 'workspace', 'live_at', 'period', 'orders', 'confirmed', 'cancelled', 'delivered', 'refused', 'open',
            'confirmation_rate', 'cancellation_rate', 'refusal_rate', 'median_minutes_to_confirm'], escape: '');

        foreach ($ids as $id) {
            $name = (string) $owner->table('workspaces')->where('id', $id)->value('name');
            $summary = $context->run($id, fn (): array => $report->summary(1));
            foreach (['baseline', 'after'] as $period) {
                $p = $summary[$period] ?? null;
                if (! is_array($p)) {
                    continue;
                }
                fputcsv($out, [$id, $name, $summary['live_at'], $period, $p['counts']['placed'], $p['counts']['confirmed'], $p['counts']['cancelled'],
                    $p['counts']['delivered'], $p['counts']['refused'], $p['counts']['open'],
                    $p['rates']['confirmation'], $p['rates']['cancellation'], $p['rates']['refusal'], $p['counts']['median_confirm_minutes']], escape: '');
            }
        }
        fclose($out);

        $this->info("Wrote {$ids->count()} pilot(s) to {$path}");

        return self::SUCCESS;
    }
}
