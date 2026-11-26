<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/** Implemented by the Workspaces module. Used by the workspace middleware. */
interface WorkspaceMembership
{
    public function isMember(string $workspaceId, string $userId): bool;
}
