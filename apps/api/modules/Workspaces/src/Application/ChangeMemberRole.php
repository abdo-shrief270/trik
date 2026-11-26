<?php

declare(strict_types=1);

namespace Modules\Workspaces\Application;

use App\Support\Authorization\PermissionRegistry;
use App\Support\Authorization\WorkspacePermissions;
use App\Support\Http\Problem;
use Illuminate\Support\Facades\DB;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\CustomRole;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

/**
 * Team rules, enforced here so every client gets them:
 *  - nobody changes their own role;
 *  - only an owner can make or unmake an owner;
 *  - a workspace always keeps at least one owner;
 *  - nobody gives a role, or changes a person, with more permissions than they have themselves.
 * Must run inside the workspace.
 */
final class ChangeMemberRole
{
    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly WorkspacePermissions $permissions,
    ) {}

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

            $newGrants = $role->grants();

            if ($role === WorkspaceRole::Custom) {
                $custom = $customRoleId !== null ? CustomRole::query()->find($customRoleId) : null;

                if ($custom === null) {
                    throw Problem::invalid(['custom_role_id' => ['Pick one of this workspace\'s custom roles.']]);
                }

                $newGrants = array_values($custom->permissions);
            } else {
                $customRoleId = null;
            }

            if ($actor->role !== WorkspaceRole::Owner) {
                $mine = $this->registry->expand($this->grants($workspaceId, $actorId));
                $theirs = $this->registry->expand($this->grants($workspaceId, $targetId));

                if (array_diff($this->registry->expand($newGrants), $mine) !== [] || array_diff($theirs, $mine) !== []) {
                    throw Problem::forbidden('beyond-own-permissions', 'You can only manage people and roles within your own permissions.');
                }
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

            if ($actorId !== $targetId && $actor->role !== WorkspaceRole::Owner
                && array_diff($this->registry->expand($this->grants($workspaceId, $targetId)), $this->registry->expand($this->grants($workspaceId, $actorId))) !== []) {
                throw Problem::forbidden('beyond-own-permissions', 'You can only remove people whose permissions you have yourself.');
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

    /** @return list<string> */
    private function grants(string $workspaceId, string $userId): array
    {
        return $this->permissions->access($workspaceId, $userId)->grants ?? [];
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
