<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\LimitRequestSize;
use App\Http\Middleware\ResolveWorkspace;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Http\Idempotency;
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
            Route::get('/readyz', [HealthController::class, 'ready'])->name('health.ready');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([RequestId::class, LimitRequestSize::class]);
        $middleware->append(SecurityHeaders::class);
        $middleware->statefulApi();
        $middleware->throttleApi();
        $middleware->api(append: [Idempotency::class]);
        $middleware->alias([
            'workspace' => ResolveWorkspace::class,
            'idempotency' => Idempotency::class,
            'verified.email' => EnsureEmailIsVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request): bool => $request->is('v1/*', 'webhooks/*', 'healthz', 'readyz') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($isApi);
        $exceptions->render(fn (Throwable $e, Request $request) => $isApi($request) ? ProblemRenderer::render($e, $request) : null);
    })->create();
