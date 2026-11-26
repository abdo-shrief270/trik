<?php

declare(strict_types=1);

namespace Modules\Workspaces\Application;

use Modules\Workspaces\Infrastructure\Models\OrganizationMember;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

/** Read side: which workspaces and organizations a user belongs to. Only theirs, never more. */
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
                $m->workspace->organization_id,
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

    /**
     * The user's organizations, each with only the workspaces they are a member of
     * (an agent in 2 of an organization's 3 workspaces sees those 2). For the switcher.
     *
     * @return list<OrganizationData>
     */
    public function organizationsFor(string $userId): array
    {
        $workspaces = $this->forUser($userId);
        $memberships = OrganizationMember::query()->with('organization')->where('user_id', $userId)->orderBy('created_at')->get();
        $result = [];

        foreach ($memberships as $m) {
            $result[] = new OrganizationData(
                $m->organization->id,
                $m->organization->name,
                $m->role,
                array_values(array_filter($workspaces, fn (WorkspaceData $w): bool => $w->organizationId === $m->organization_id)),
            );
        }

        return $result;
    }

    /** @return list<string> the user's workspace ids inside one organization */
    public function workspaceIdsIn(string $organizationId, string $userId): array
    {
        return array_values(array_map(
            fn (WorkspaceData $w): string => $w->id,
            array_filter($this->forUser($userId), fn (WorkspaceData $w): bool => $w->organizationId === $organizationId),
        ));
    }
}
