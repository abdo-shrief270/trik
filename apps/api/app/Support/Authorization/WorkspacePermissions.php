<?php

declare(strict_types=1);

namespace App\Support\Authorization;

/** Implemented by the Workspaces module: a member's role and grants. Null if not a member. */
interface WorkspacePermissions
{
    public function access(string $workspaceId, string $userId): ?MemberAccess;
}
