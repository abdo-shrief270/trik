<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Base class for every module's service provider.
 *
 * By convention a module lives in modules/{Name} and its provider is
 * Modules\{Name}\{Name}ServiceProvider. This base class loads by convention:
 *  - migrations from database/migrations
 *  - src/Http/routes.php under /api/v1 with the "api" middleware group
 *  - src/Http/webhooks.php under /webhooks with no session, CSRF or throttling
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerModule();
    }

    /** Hook for module-specific bindings. */
    protected function registerModule(): void {}

    public function boot(): void
    {
        $base = $this->modulePath();

        if (is_dir($base.'/database/migrations')) {
            $this->loadMigrationsFrom($base.'/database/migrations');
        }

        $routes = $base.'/src/Http/routes.php';

        if (is_file($routes) && ! $this->app->routesAreCached()) {
            Route::prefix('api/v1')->middleware('api')->group($routes);
        }

        $webhooks = $base.'/src/Http/webhooks.php';

        if (is_file($webhooks) && ! $this->app->routesAreCached()) {
            Route::prefix('webhooks')->middleware(SubstituteBindings::class)->group($webhooks);
        }

        $this->bootModule();
    }

    /** Hook for module-specific boot logic. */
    protected function bootModule(): void {}

    public function moduleName(): string
    {
        return ModuleRegistry::nameFromProvider(static::class);
    }

    protected function modulePath(): string
    {
        return base_path('modules/'.$this->moduleName());
    }
}
