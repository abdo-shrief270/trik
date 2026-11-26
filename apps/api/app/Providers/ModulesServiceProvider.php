<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

/** Registers the service provider of every module found in modules/. */
final class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach (ModuleRegistry::providers(base_path('modules')) as $provider) {
            $this->app->register($provider);
        }
    }
}
