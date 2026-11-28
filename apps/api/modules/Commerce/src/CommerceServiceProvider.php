<?php

declare(strict_types=1);

namespace Modules\Commerce;

use App\Support\Modules\ModuleServiceProvider;
use App\Support\Privacy\PersonalDataRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Modules\Commerce\Application\CommercePersonalData;
use Modules\Commerce\Application\WriteBackListener;
use Modules\Commerce\Infrastructure\Console\SweepCommand;
use Modules\Orders\Application\Events\OrderChanged;

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
        app(PersonalDataRegistry::class)->register(CommercePersonalData::class);
        Event::listen(OrderChanged::class, [WriteBackListener::class, 'orderChanged']);

        if ($this->app->runningInConsole()) {
            $this->commands([SweepCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('commerce:sweep')->everyMinute()->onOneServer()->withoutOverlapping();
        });
    }
}
