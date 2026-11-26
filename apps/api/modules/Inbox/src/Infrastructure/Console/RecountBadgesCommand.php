<?php

declare(strict_types=1);

namespace Modules\Inbox\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Inbox\Application\BadgeCounts;

/** Safety net for the trigger-maintained badge counters: rebuilds them, one workspace at a time. */
final class RecountBadgesCommand extends Command
{
    protected $signature = 'inbox:recount-badges {--workspace= : only this workspace}';

    protected $description = 'Rebuild the inbox badge counters from the conversations';

    public function handle(): int
    {
        $ids = $this->option('workspace') !== null
            ? [(string) $this->option('workspace')]
            : DB::connection('owner')->table('inbox_conversations')->distinct()->pluck('workspace_id')
                ->merge(DB::connection('owner')->table('inbox_badge_counts')->distinct()->pluck('workspace_id'))
                ->unique()->map(fn (mixed $id): string => (string) $id)->values()->all();

        foreach ($ids as $id) {
            BadgeCounts::recount($id);
        }

        $this->info('Recounted '.count($ids).' workspace(s).');

        return self::SUCCESS;
    }
}
