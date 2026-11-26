<?php

declare(strict_types=1);

namespace Modules\WhatsApp;

use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\WhatsApp\Infrastructure\Console\MaintainCommand;
use Modules\WhatsApp\Infrastructure\Providers\FakeWhatsApp;

final class WhatsAppServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->singleton(FakeWhatsApp::class);
    }

    protected function permissions(): array
    {
        return [
            'whatsapp.numbers.view' => 'See connected WhatsApp numbers',
            'whatsapp.numbers.manage' => 'Connect and disconnect WhatsApp numbers',
            'whatsapp.templates.view' => 'See message templates',
            'whatsapp.templates.manage' => 'Create and delete message templates',
            'whatsapp.messages.view' => 'Read the message log',
            'whatsapp.messages.send' => 'Send messages',
        ];
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MaintainCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('whatsapp:maintain')->dailyAt('02:15')->onOneServer()->withoutOverlapping();
        });
    }
}
