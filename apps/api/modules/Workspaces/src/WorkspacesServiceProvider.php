<?php

declare(strict_types=1);

namespace Modules\Workspaces;

use App\Support\Modules\ModuleServiceProvider;
use App\Support\Tenancy\WorkspaceMembership;
use Modules\Workspaces\Infrastructure\EloquentWorkspaceMembership;

final class WorkspacesServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WorkspaceMembership::class, EloquentWorkspaceMembership::class);
    }
}
