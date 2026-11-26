<?php

declare(strict_types=1);

namespace Modules\Confirmation;

use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Modules\Confirmation\Application\Listeners;
use Modules\Confirmation\Infrastructure\Console\TickCommand;
use Modules\Orders\Application\Events\OrderChanged;
use Modules\WhatsApp\Application\Events\MessageReceived;

/** The confirmation engine: template on new COD orders, button replies, reminders, escalation. */
final class ConfirmationServiceProvider extends ModuleServiceProvider
{
    protected function permissions(): array
    {
        return [
            'confirmation.settings.view' => 'See the order confirmation settings',
            'confirmation.settings.manage' => 'Turn order confirmation on and set its template, delays and replies',
        ];
    }

    protected function bootModule(): void
    {
        Event::listen(OrderChanged::class, [Listeners::class, 'orderChanged']);
        Event::listen(MessageReceived::class, [Listeners::class, 'messageReceived']);

        if ($this->app->runningInConsole()) {
            $this->commands([TickCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('confirmation:tick')->everyMinute()->onOneServer()->withoutOverlapping();
        });
    }
}
