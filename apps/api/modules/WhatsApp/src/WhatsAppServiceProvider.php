<?php

declare(strict_types=1);

namespace Modules\WhatsApp;

use App\Support\Modules\ModuleServiceProvider;
use Modules\WhatsApp\Infrastructure\Providers\FakeWhatsApp;

final class WhatsAppServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->singleton(FakeWhatsApp::class);
    }
}
