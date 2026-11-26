<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Authorization\PermissionGate;
use App\Support\Authorization\PermissionRegistry;
use App\Support\Events\EventPublisher;
use App\Support\Events\LaravelEventPublisher;
use App\Support\Tenancy\QueueTenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(QueueTenancy::class);
        $this->app->bind(EventPublisher::class, LaravelEventPublisher::class);
        $this->app->singleton(PermissionRegistry::class);
        $this->app->scoped(PermissionGate::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::automaticallyEagerLoadRelationships();

        $this->bootDatabaseRole();

        Gate::before(fn (?Authenticatable $user, string $ability) => $this->app->make(PermissionGate::class)->before($user, $ability));

        $this->app->make(QueueTenancy::class)->register($this->app->make('queue'), $this->app->make('events'));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));
    }

    /**
     * The restricted role is applied at connect time through server_options (see config/database.php).
     * If a workspace was activated before the connection opened, apply it now.
     */
    private function bootDatabaseRole(): void
    {
        Event::listen(function (ConnectionEstablished $event): void {
            $context = $this->app->make(TenantContext::class);

            if ($context->has() && $event->connection->getName() === $this->app->make(DatabaseManager::class)->getDefaultConnection()) {
                $context->apply($event->connection);
            }
        });
    }
}
