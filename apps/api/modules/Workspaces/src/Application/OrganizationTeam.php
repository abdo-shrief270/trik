<?php

declare(strict_types=1);

namespace Modules\Workspaces\Application;

use App\Support\Http\Problem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Workspaces\Domain\OrganizationRole;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\CustomRole;
use Modules\Workspaces\Infrastructure\Models\OrganizationMember;
use Modules\Workspaces\Infrastructure\Models\Workspace;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

/**
 * Team changes made from the organization (ADR 0012, §2). Rules:
 *  - nobody changes their own organization role;
 *  - only an owner makes or unmakes an owner, at either level;
 *  - non-owners never manage someone at or above their own organization role;
 *  - an organization always keeps an owner, and so does each workspace;
 *  - people are added to workspaces only from the organization's people list;
 *  - every change is written to the organization audit log, and workspace changes also to
 *    that workspace's audit log (visible to its admins).
 */
final class OrganizationTeam
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly TenantContext $context,
    ) {}

    public function changeRole(string $orgId, string $actorId, string $targetId, OrganizationRole $role): OrganizationMember
    {
        return DB::transaction(function () use ($orgId, $actorId, $targetId, $role): OrganizationMember {
            [$actor, $target] = $this->orgMembers($orgId, $actorId, $targetId);

            if ($actorId === $targetId) {
                throw Problem::forbidden('cannot-change-own-role', 'Ask another owner or admin to change your organization role.');
            }

            $this->assertCanManage($actor, $target, $role);

            if ($target->role === OrganizationRole::Owner && $role !== OrganizationRole::Owner) {
                $this->assertAnotherOrgOwner($orgId, $targetId);
            }

            $from = $target->role;
            $target->forceFill(['role' => $role])->save();
            $this->audit->organization($orgId, 'organization.member.role_changed', $actorId, $targetId, data: ['from' => $from->value, 'to' => $role->value]);

            return $target;
        });
    }

    /** Removes someone from the organization and from all its workspaces. Anyone may leave. */
    public function remove(string $orgId, string $actorId, string $targetId): void
    {
        DB::transaction(function () use ($orgId, $actorId, $targetId): void {
            [$actor, $target] = $this->orgMembers($orgId, $actorId, $targetId);

            if ($actorId !== $targetId) {
                $this->assertCanManage($actor, $target, null);
            }

            if ($target->role === OrganizationRole::Owner) {
                $this->assertAnotherOrgOwner($orgId, $targetId);
            }

            $memberships = WorkspaceMember::query()
                ->where('user_id', $targetId)
                ->whereIn('workspace_id', Workspace::query()->where('organization_id', $orgId)->select('id'))
                ->lockForUpdate()
                ->get();

            foreach ($memberships as $m) {
                if ($m->role === WorkspaceRole::Owner) {
                    $this->assertAnotherWorkspaceOwner($m->workspace_id, $targetId);
                }
            }

            foreach ($memberships as $m) {
                $m->delete();
                $this->audit->workspace($m->workspace_id, 'member.removed', $actorId, $targetId, ['via' => 'organization']);
            }

            $target->delete();
            $this->audit->organization($orgId, $actorId === $targetId ? 'organization.member.left' : 'organization.member.removed', $actorId, $targetId);
        });
    }

    public function addToWorkspace(string $orgId, string $actorId, string $workspaceId, string $targetId, WorkspaceRole $role, ?string $customRoleId): WorkspaceMember
    {
        return DB::transaction(function () use ($orgId, $actorId, $workspaceId, $targetId, $role, $customRoleId): WorkspaceMember {
            $actor = $this->orgMember($orgId, $actorId);
            $this->workspaceIn($orgId, $workspaceId);

            if ($this->orgMemberOrNull($orgId, $targetId) === null) {
                throw Problem::unprocessable('not-in-organization', 'Only people in the organization can be added to its workspaces. Invite them first.');
            }

            if (WorkspaceMember::query()->where('workspace_id', $workspaceId)->where('user_id', $targetId)->exists()) {
                throw Problem::conflict('already-a-member', 'This person is already in the workspace. Change their role instead.');
            }

            $customRoleId = $this->checkWorkspaceRole($actor, $workspaceId, $role, $customRoleId);

            $member = WorkspaceMember::query()->create([
                'workspace_id' => $workspaceId, 'user_id' => $targetId, 'role' => $role, 'custom_role_id' => $customRoleId,
            ]);

            $data = ['role' => $role->value, 'custom_role_id' => $customRoleId, 'via' => 'organization', 'self' => $actorId === $targetId];
            $this->audit->workspace($workspaceId, 'member.added', $actorId, $targetId, $data);
            $this->audit->organization($orgId, 'workspace.member.added', $actorId, $targetId, $workspaceId, $data);

            return $member;
        });
    }

    public function changeWorkspaceRole(string $orgId, string $actorId, string $workspaceId, string $targetId, WorkspaceRole $role, ?string $customRoleId): WorkspaceMember
    {
        return DB::transaction(function () use ($orgId, $actorId, $workspaceId, $targetId, $role, $customRoleId): WorkspaceMember {
            $actor = $this->orgMember($orgId, $actorId);
            $this->workspaceIn($orgId, $workspaceId);
            $member = $this->workspaceMember($workspaceId, $targetId);

            if ($member->role === WorkspaceRole::Owner && $actor->role !== OrganizationRole::Owner) {
                throw Problem::forbidden('owner-only', 'Only an organization owner can change a workspace owner\'s role.');
            }

            $customRoleId = $this->checkWorkspaceRole($actor, $workspaceId, $role, $customRoleId);

            if ($member->role === WorkspaceRole::Owner && $role !== WorkspaceRole::Owner) {
                $this->assertAnotherWorkspaceOwner($workspaceId, $targetId);
            }

            $from = $member->role;
            $member->forceFill(['role' => $role, 'custom_role_id' => $customRoleId])->save();

            $data = ['from' => $from->value, 'to' => $role->value, 'custom_role_id' => $customRoleId, 'via' => 'organization'];
            $this->audit->workspace($workspaceId, 'member.role_changed', $actorId, $targetId, $data);
            $this->audit->organization($orgId, 'workspace.member.role_changed', $actorId, $targetId, $workspaceId, $data);

            return $member;
        });
    }

    public function removeFromWorkspace(string $orgId, string $actorId, string $workspaceId, string $targetId): void
    {
        DB::transaction(function () use ($orgId, $actorId, $workspaceId, $targetId): void {
            $actor = $this->orgMember($orgId, $actorId);
            $this->workspaceIn($orgId, $workspaceId);
            $member = $this->workspaceMember($workspaceId, $targetId);

            if ($member->role === WorkspaceRole::Owner) {
                if ($actor->role !== OrganizationRole::Owner) {
                    throw Problem::forbidden('owner-only', 'Only an organization owner can remove a workspace owner.');
                }

                $this->assertAnotherWorkspaceOwner($workspaceId, $targetId);
            }

            $member->delete();
            $this->audit->workspace($workspaceId, 'member.removed', $actorId, $targetId, ['via' => 'organization']);
            $this->audit->organization($orgId, 'workspace.member.removed', $actorId, $targetId, $workspaceId);
        });
    }

    /** Validates the workspace role an organization actor wants to give. Returns the custom role id to store. */
    private function checkWorkspaceRole(OrganizationMember $actor, string $workspaceId, WorkspaceRole $role, ?string $customRoleId): ?string
    {
        if ($role === WorkspaceRole::Owner && $actor->role !== OrganizationRole::Owner) {
            throw Problem::forbidden('owner-only', 'Only an organization owner can make someone a workspace owner.');
        }

        if ($role !== WorkspaceRole::Custom) {
            return null;
        }

        // custom roles are tenant data: look them up inside that workspace
        $exists = $customRoleId !== null && $this->context->run($workspaceId, fn (): bool => CustomRole::query()->whereKey($customRoleId)->exists());

        if (! $exists) {
            throw Problem::invalid(['custom_role_id' => ['Pick one of this workspace\'s custom roles.']]);
        }

        return $customRoleId;
    }

    private function assertCanManage(OrganizationMember $actor, OrganizationMember $target, ?OrganizationRole $newRole): void
    {
        if ($actor->role === OrganizationRole::Owner) {
            return;
        }

        if ($target->role === OrganizationRole::Owner || $newRole === OrganizationRole::Owner) {
            throw Problem::forbidden('owner-only', 'Only an organization owner can make or unmake an owner.');
        }

        if ($target->role->rank() >= $actor->role->rank() || ($newRole !== null && $newRole->rank() > $actor->role->rank())) {
            throw Problem::forbidden('beyond-own-permissions', 'You can only manage people below your own organization role.');
        }
    }

    /** @return array{0: OrganizationMember, 1: OrganizationMember} */
    private function orgMembers(string $orgId, string $actorId, string $targetId): array
    {
        $rows = OrganizationMember::query()->where('organization_id', $orgId)->whereIn('user_id', [$actorId, $targetId])->lockForUpdate()->get()->keyBy('user_id');
        $actor = $rows->get($actorId);
        $target = $rows->get($targetId);

        if ($actor === null || $target === null) {
            throw Problem::notFound('member-not-found', 'That person is not in this organization.');
        }

        return [$actor, $target];
    }

    private function orgMember(string $orgId, string $userId): OrganizationMember
    {
        return $this->orgMemberOrNull($orgId, $userId) ?? throw Problem::notFound('member-not-found', 'That person is not in this organization.');
    }

    private function orgMemberOrNull(string $orgId, string $userId): ?OrganizationMember
    {
        return OrganizationMember::query()->where('organization_id', $orgId)->where('user_id', $userId)->first();
    }

    private function workspaceIn(string $orgId, string $workspaceId): Workspace
    {
        return Workspace::query()->whereKey($workspaceId)->where('organization_id', $orgId)->first()
            ?? throw Problem::notFound('workspace-not-found', 'This workspace is not part of the organization.');
    }

    private function workspaceMember(string $workspaceId, string $userId): WorkspaceMember
    {
        return WorkspaceMember::query()->where('workspace_id', $workspaceId)->where('user_id', $userId)->lockForUpdate()->first()
            ?? throw Problem::notFound('member-not-found', 'That person is not in this workspace.');
    }

    private function assertAnotherOrgOwner(string $orgId, string $exceptUserId): void
    {
        $others = OrganizationMember::query()->where('organization_id', $orgId)->where('role', OrganizationRole::Owner)
            ->where('user_id', '!=', $exceptUserId)->lockForUpdate()->pluck('user_id');

        if ($others->isEmpty()) {
            throw Problem::conflict('last-owner', 'An organization needs at least one owner. Make someone else an owner first.');
        }
    }

    private function assertAnotherWorkspaceOwner(string $workspaceId, string $exceptUserId): void
    {
        $others = WorkspaceMember::query()->where('workspace_id', $workspaceId)->where('role', WorkspaceRole::Owner)
            ->where('user_id', '!=', $exceptUserId)->lockForUpdate()->pluck('user_id');

        if ($others->isEmpty()) {
            throw Problem::conflict('last-workspace-owner', 'Each workspace needs an owner. Make someone else the owner of that workspace first.');
        }
    }
}
