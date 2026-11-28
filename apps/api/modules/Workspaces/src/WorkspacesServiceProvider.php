<?php

declare(strict_types=1);

namespace Modules\Workspaces;

use App\Support\Authorization\WorkspacePermissions;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Tenancy\WorkspaceMembership;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Modules\Workspaces\Application\ActiveOrganization;
use Modules\Workspaces\Http\Middleware\ResolveOrganization;
use Modules\Workspaces\Infrastructure\EloquentWorkspaceMembership;
use Modules\Workspaces\Infrastructure\EloquentWorkspacePermissions;
use Modules\Workspaces\Infrastructure\OrganizationGate;

final class WorkspacesServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->bind(WorkspaceMembership::class, EloquentWorkspaceMembership::class);
        $this->app->bind(WorkspacePermissions::class, EloquentWorkspacePermissions::class);
        $this->app->scoped(ActiveOrganization::class);
    }

    protected function bootModule(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('organization', ResolveOrganization::class);

        // organization.* abilities are answered from the organization role, never workspace grants
        // app(): resolve from the current request's container (Octane), where ActiveOrganization was set
        Gate::before(fn (?Authenticatable $user, string $ability) => $user === null ? null : app(OrganizationGate::class)->before($ability));
    }

    protected function permissions(): array
    {
        return [
            'workspace.workspace.update' => 'Rename the workspace and change its settings',
            'workspace.workspace.delete' => 'Delete the workspace (owners only)',
            'workspace.members.view' => 'See the team and the roles',
            'workspace.members.manage' => 'Invite people, change members\' roles and remove members',
            'workspace.roles.manage' => 'Create, edit and delete custom roles',
            'workspace.audit.view' => 'Read the workspace audit log (team and settings changes)',
        ];
    }
}
