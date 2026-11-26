<?php

declare(strict_types=1);

namespace Modules\Workspaces\Infrastructure;

use App\Support\Authorization\MemberAccess;
use App\Support\Authorization\WorkspacePermissions;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\CustomRole;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

final class EloquentWorkspacePermissions implements WorkspacePermissions
{
    public function access(string $workspaceId, string $userId): ?MemberAccess
    {
        $member = WorkspaceMember::query()->where('workspace_id', $workspaceId)->where('user_id', $userId)->first();

        if ($member === null) {
            return null;
        }

        if ($member->role !== WorkspaceRole::Custom) {
            return new MemberAccess($member->role->value, $member->role->grants());
        }

        // Custom roles are tenant data (row-level security): read them inside the workspace.
        $role = $member->custom_role_id !== null ? CustomRole::query()->find($member->custom_role_id) : null;

        return new MemberAccess(WorkspaceRole::Custom->value, array_values($role->permissions ?? []), $member->custom_role_id);
    }
}
