<?php

declare(strict_types=1);

namespace Modules\Risk\Infrastructure\Console;

use App\Http\Middleware\EnsureFeatureEnabled;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Risk\Application\Jobs\RescoreWorkspace;

/**
 * Scores the open orders of the given workspaces, or of every workspace where `risk` is on (owner
 * connection, ids only). Run it after turning the feature on for pilot sellers.
 */
final class RescoreCommand extends Command
{
    protected $signature = 'risk:rescore {workspace?* : Workspace ids (default: every workspace with the feature on)}';

    protected $description = 'Score the open orders of workspaces where refusal risk is on';

    public function handle(): int
    {
        /** @var list<string> $given */
        $given = array_values(array_filter((array) $this->argument('workspace'), fn (mixed $id): bool => is_string($id) && Str::isUuid($id)));
        $ids = $given !== [] ? $given : DB::connection('owner')->table('workspaces')->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

        $queued = 0;
        foreach ($ids as $id) {
            if (EnsureFeatureEnabled::enabled('risk', $id)) {
                RescoreWorkspace::dispatch($id);
                $queued++;
            }
        }

        $this->info("Queued {$queued} workspace(s).");

        return self::SUCCESS;
    }
}
