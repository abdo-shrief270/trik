<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Tenancy\QueueTenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(QueueTenancy::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::automaticallyEagerLoadRelationships();

        $this->bootDatabaseRole();

        $this->app->make(QueueTenancy::class)->register($this->app->make('queue'), $this->app->make('events'));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));
    }

    /**
     * The app connection drops to the restricted role (DB_ROLE) as soon as it connects,
     * so row-level security always applies to it. The "owner" connection has no role set.
     */
    private function bootDatabaseRole(): void
    {
        Event::listen(function (ConnectionEstablished $event): void {
            $connection = $event->connection;
            $role = $connection->getConfig('role');

            if ($connection->getDriverName() !== 'pgsql') {
                return;
            }

            if (is_string($role) && $role !== '') {
                if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
                    throw new \InvalidArgumentException('DB_ROLE must be a plain identifier.');
                }
                $connection->statement('set role "'.$role.'"');
            }

            $this->app->make(TenantContext::class)->apply($connection);
        });
    }
}
