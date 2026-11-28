<?php

declare(strict_types=1);

namespace Modules\Analytics;

use App\Support\Modules\ModuleServiceProvider;
use Modules\Analytics\Infrastructure\Console\PilotReportCommand;

/** Reports. First: pilot results, before vs after Trik (#345). */
final class AnalyticsServiceProvider extends ModuleServiceProvider
{
    protected function permissions(): array
    {
        return [
            'analytics.reports.view' => 'See results and download reports',
            'analytics.reports.manage' => 'Set the go-live date of the results',
        ];
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PilotReportCommand::class]);
        }
    }
}
