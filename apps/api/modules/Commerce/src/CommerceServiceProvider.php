<?php

declare(strict_types=1);

namespace Modules\Commerce;

use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Commerce\Infrastructure\Console\SweepCommand;

/** Store connectors: Shopify (#332), then EasyOrders (#117). */
final class CommerceServiceProvider extends ModuleServiceProvider
{
    protected function permissions(): array
    {
        return [
            'commerce.stores.view' => 'See connected stores and their import status',
            'commerce.stores.manage' => 'Connect, disconnect and re-import stores',
        ];
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SweepCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('commerce:sweep')->everyMinute()->onOneServer()->withoutOverlapping();
        });
    }
}
