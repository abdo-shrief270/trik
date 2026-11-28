<?php

declare(strict_types=1);

namespace Modules\Risk;

use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Modules\Orders\Application\Events\OrderChanged;
use Modules\Risk\Application\RiskListener;
use Modules\Risk\Infrastructure\Console\RescoreCommand;

/** Refusal risk (#115): an explainable score per order, from the seller's own data. */
final class RiskServiceProvider extends ModuleServiceProvider
{
    protected function permissions(): array
    {
        return [
            'risk.settings.view' => 'See the refusal risk threshold and weights',
            'risk.settings.manage' => 'Change the refusal risk threshold and weights',
        ];
    }

    protected function bootModule(): void
    {
        Event::listen(OrderChanged::class, [RiskListener::class, 'orderChanged']);

        if ($this->app->runningInConsole()) {
            $this->commands([RescoreCommand::class]);
        }
    }
}
