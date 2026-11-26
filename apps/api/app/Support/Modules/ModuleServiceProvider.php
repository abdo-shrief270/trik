<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Base class for every module's service provider.
 *
 * By convention a module lives in modules/{Name} and its provider is
 * Modules\{Name}\{Name}ServiceProvider. This base class loads the module's
 * migrations (database/migrations) and HTTP routes (src/Http/routes.php),
 * which are mounted under /api/v1 with the "api" middleware group.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
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
