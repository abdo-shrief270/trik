<?php

declare(strict_types=1);

namespace Modules\Workspaces\Application;

use App\Support\Http\Problem;
use Illuminate\Support\Facades\DB;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\CustomRole;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

/**
 * Team rules, enforced here so every client gets them:
 *  - nobody changes their own role;
 *  - only an owner can make or unmake an owner;
 *  - a workspace always keeps at least one owner.
 * Must run inside the workspace.
 */
final class ChangeMemberRole
{
    public function handle(string $workspaceId, string $actorId, string $targetId, WorkspaceRole $role, ?string $customRoleId = null): WorkspaceMember
    {
        return DB::transaction(function () use ($workspaceId, $actorId, $targetId, $role, $customRoleId): WorkspaceMember {
            [$actor, $target] = $this->members($workspaceId, $actorId, $targetId);

            if ($actorId === $targetId) {
                throw Problem::forbidden('cannot-change-own-role', 'Ask another admin or owner to change your role.');
            }

            if (($target->role === WorkspaceRole::Owner || $role === WorkspaceRole::Owner) && $actor->role !== WorkspaceRole::Owner) {
                throw Problem::forbidden('owner-only', 'Only an owner can make someone an owner or change an owner\'s role.');
            }

            if ($role === WorkspaceRole::Custom) {
                if ($customRoleId === null || CustomRole::query()->whereKey($customRoleId)->doesntExist()) {
                    throw Problem::invalid(['custom_role_id' => ['Pick one of this workspace\'s custom roles.']]);
                }
            } else {
                $customRoleId = null;
            }

            if ($target->role === WorkspaceRole::Owner && $role !== WorkspaceRole::Owner) {
                $this->assertAnotherOwner($workspaceId, $targetId);
            }

            $target->forceFill(['role' => $role, 'custom_role_id' => $customRoleId])->save();

            return $target;
        });
    }

    public function remove(string $workspaceId, string $actorId, string $targetId): void
    {
        DB::transaction(function () use ($workspaceId, $actorId, $targetId): void {
            [$actor, $target] = $this->members($workspaceId, $actorId, $targetId);

            if ($target->role === WorkspaceRole::Owner && $actorId !== $targetId && $actor->role !== WorkspaceRole::Owner) {
                throw Problem::forbidden('owner-only', 'Only an owner can remove an owner.');
            }

            if ($target->role === WorkspaceRole::Owner) {
                $this->assertAnotherOwner($workspaceId, $targetId);
            }

            $target->delete();
        });
    }

    /** @return array{0: WorkspaceMember, 1: WorkspaceMember} */
    private function members(string $workspaceId, string $actorId, string $targetId): array
    {
        $members = WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('user_id', [$actorId, $targetId])
            ->lockForUpdate()
            ->get()
            ->keyBy('user_id');

        $actor = $members->get($actorId);
        $target = $members->get($targetId);

        if ($actor === null || $target === null) {
            throw Problem::notFound('member-not-found', 'That person is not a member of this workspace.');
        }

        return [$actor, $target];
    }

    private function assertAnotherOwner(string $workspaceId, string $exceptUserId): void
    {
        // lock the other owners' rows so two owners can't demote each other at the same time
        $others = WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->where('role', WorkspaceRole::Owner)
            ->where('user_id', '!=', $exceptUserId)
            ->lockForUpdate()
            ->pluck('user_id');

        if ($others->isEmpty()) {
            throw Problem::conflict('last-owner', 'A workspace needs at least one owner. Make someone else an owner first.');
        }
    }
}
