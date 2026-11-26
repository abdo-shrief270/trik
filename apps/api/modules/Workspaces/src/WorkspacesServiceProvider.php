<?php

declare(strict_types=1);

namespace Modules\Workspaces;

use App\Support\Authorization\WorkspacePermissions;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Tenancy\WorkspaceMembership;
use Modules\Workspaces\Infrastructure\EloquentWorkspaceMembership;
use Modules\Workspaces\Infrastructure\EloquentWorkspacePermissions;

final class WorkspacesServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->bind(WorkspaceMembership::class, EloquentWorkspaceMembership::class);
        $this->app->bind(WorkspacePermissions::class, EloquentWorkspacePermissions::class);
    }

    protected function permissions(): array
    {
        return [
            'workspace.workspace.update' => 'Rename the workspace and change its settings',
            'workspace.workspace.delete' => 'Delete the workspace (owners only)',
            'workspace.members.view' => 'See the team and the roles',
            'workspace.members.manage' => 'Change members\' roles and remove members',
            'workspace.roles.manage' => 'Create, edit and delete custom roles',
        ];
    }
}
