<?php

declare(strict_types=1);

namespace Modules\Waitlist;

use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Waitlist\Infrastructure\Console\ExportCommand;
use Modules\Waitlist\Infrastructure\Models\WaitlistEntry;

/** The public pilot waitlist on trik.chat: double opt-in sign-up, confirm and unsubscribe links, export. */
final class WaitlistServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        $this->loadViewsFrom(dirname(__DIR__).'/resources/views', 'waitlist');

        // Per IP (a form, not an API) and per email (so nobody's inbox can be flooded from many IPs).
        RateLimiter::for('waitlist', function (Request $request): array {
            $email = is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : '';

            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perHour(30)->by('ip-hour:'.$request->ip()),
                Limit::perHour(5)->by('email:'.hash('sha256', $email)),
            ];
        });

        if ($this->app->runningInConsole()) {
            $this->commands([ExportCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('model:prune', ['--model' => [WaitlistEntry::class]])->dailyAt('03:40')->onOneServer();
        });
    }
}
