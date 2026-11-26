<?php

declare(strict_types=1);

namespace Modules\Workspaces\Application;

use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

/** Read side: which workspaces a user belongs to. */
final class WorkspaceDirectory
{
    /** @return list<WorkspaceData> */
    public function forUser(string $userId): array
    {
        return array_values(WorkspaceMember::query()
            ->with('workspace')
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (WorkspaceMember $m): WorkspaceData => new WorkspaceData(
                $m->workspace->id,
                $m->workspace->name,
                $m->workspace->slug,
                $m->role,
            ))
            ->all());
    }

    public function find(string $workspaceId, string $userId): ?WorkspaceData
    {
        foreach ($this->forUser($userId) as $workspace) {
            if ($workspace->id === $workspaceId) {
                return $workspace;
            }
        }

        return null;
    }
}
