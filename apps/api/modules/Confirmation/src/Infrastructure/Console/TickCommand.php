<?php

declare(strict_types=1);

namespace Modules\Confirmation\Infrastructure\Console;

use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Confirmation\Application\Jobs\AdvanceRun;

/**
 * Every minute: queue the runs whose next step is due (reminders, escalations, and any first
 * message whose job was lost). Reads across workspaces on the owner connection (ids only, through
 * the partial index), then queues each job inside its own workspace.
 */
final class TickCommand extends Command
{
    protected $signature = 'confirmation:tick {--limit=5000}';

    protected $description = 'Queue due confirmation reminders and escalations';

    public function handle(TenantContext $tenant): int
    {
        $due = DB::connection('owner')->select(
            "select id, workspace_id from confirmation_runs where status in ('pending', 'sent') and next_action_at <= now() order by next_action_at limit ?",
            [(int) $this->option('limit')],
        );

        foreach ($due as $row) {
            $tenant->run((string) $row->workspace_id, fn () => AdvanceRun::dispatch((string) $row->id));
        }

        $this->info('Queued '.count($due).' run(s).');

        return self::SUCCESS;
    }
}
