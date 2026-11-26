<?php

declare(strict_types=1);

use Modules\Identity\IdentityServiceProvider;
use Modules\Workspaces\WorkspacesServiceProvider;

it('registers module providers found on disk', function (): void {
    expect(app()->getProviders(IdentityServiceProvider::class))->not->toBeEmpty()
        ->and(app()->getProviders(WorkspacesServiceProvider::class))->not->toBeEmpty();
});

it('mounts module routes under /api/v1', function (): void {
    expect(route('identity.login', absolute: false))->toBe('/api/v1/auth/login')
        ->and(route('workspaces.index', absolute: false))->toBe('/api/v1/workspaces');
});
