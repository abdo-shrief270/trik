<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

// Production caches routes (`route:cache` when the API starts). A duplicate route name works uncached but makes
// the cache, and so the release, fail: v1.0.0's first deploy did exactly that.

it('gives every route a unique name', function (): void {
    $names = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->countBy()
        ->filter(fn (int $count) => $count > 1);

    expect($names->keys()->all())->toBe([]);
});

it('can cache the routes, as production does', function (): void {
    try {
        expect(Artisan::call('route:cache'))->toBe(0);
    } finally {
        Artisan::call('route:clear');
    }
});
