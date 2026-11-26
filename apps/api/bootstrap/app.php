<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\LimitRequestSize;
use App\Http\Middleware\RejectMethodOverride;
use App\Http\Middleware\ResolveWorkspace;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Http\Idempotency;
use App\Support\Http\Problem;
use App\Support\Http\ProblemRenderer;
use App\Support\Http\RequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            Route::get('/healthz', [HealthController::class, 'live'])->name('health.live');
            // readiness touches the database and cache: throttled so it can't be used to load them
            Route::get('/readyz', [HealthController::class, 'ready'])->middleware('throttle:health')->name('health.ready');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Caddy (and Cloudflare), the client IP and scheme come from X-Forwarded-*. Trust only
        // the proxy addresses: otherwise every user shares the proxy's IP in rate limits and lockouts,
        // and trusting everyone would let clients fake their IP.
        $middleware->trustProxies(at: array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1'))))));
        $middleware->prepend([RequestId::class, LimitRequestSize::class, RejectMethodOverride::class]);
        $middleware->append(SecurityHeaders::class);
        $middleware->statefulApi();
        $middleware->throttleApi();
        $middleware->api(append: [Idempotency::class]);
        $middleware->alias([
            'workspace' => ResolveWorkspace::class,
            'idempotency' => Idempotency::class,
            'verified.email' => EnsureEmailIsVerified::class,
            'feature' => EnsureFeatureEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request): bool => $request->is('v1/*', 'webhooks/*', 'healthz', 'readyz') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($isApi);
        // A client error is an answer, not an incident: only 5xx problems go to the error log
        // (otherwise anyone could flood the log, and alerts, with 403s and 422s).
        $exceptions->dontReportWhen(fn (Throwable $e): bool => $e instanceof Problem && $e->status < 500);
        $exceptions->render(fn (Throwable $e, Request $request) => $isApi($request) ? ProblemRenderer::render($e, $request) : null);
    })->create();
