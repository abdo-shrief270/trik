<?php

declare(strict_types=1);

namespace Modules\Workspaces\Infrastructure;

use App\Support\Tenancy\WorkspaceMembership;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

final class EloquentWorkspaceMembership implements WorkspaceMembership
{
    public function isMember(string $workspaceId, string $userId): bool
    {
        return WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->exists();
    }
}
