<?php

declare(strict_types=1);

namespace Modules\Compliance\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Daily (owner connection, across workspaces): drops data exports past their expiry. The request rows stay, as the record. */
final class PruneCommand extends Command
{
    protected $signature = 'compliance:prune';

    protected $description = 'Drop expired privacy data exports';

    public function handle(): int
    {
        $dropped = DB::connection('owner')->table('compliance_requests')
            ->whereNotNull('export')
            ->where('export_expires_at', '<', now())
            ->update(['export' => null]);

        $this->info("Dropped {$dropped} expired export(s).");

        return self::SUCCESS;
    }
}
