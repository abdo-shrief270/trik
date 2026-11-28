<?php

declare(strict_types=1);

namespace Modules\Compliance;

use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Compliance\Infrastructure\Console\PruneCommand;

/** Privacy requests (store platforms first, #333): data exports and redaction across modules, audited. */
final class ComplianceServiceProvider extends ModuleServiceProvider
{
    protected function permissions(): array
    {
        return [
            'compliance.requests.view' => 'See privacy requests received for the workspace',
            'compliance.requests.export' => "Download a customer's data export (personal data)",
        ];
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('compliance:prune')->dailyAt('03:40')->onOneServer()->withoutOverlapping();
        });
    }
}
