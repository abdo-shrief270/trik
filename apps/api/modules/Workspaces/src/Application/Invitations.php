<?php

declare(strict_types=1);

namespace Modules\Workspaces\Application;

use App\Support\Authorization\PermissionRegistry;
use App\Support\Authorization\WorkspacePermissions;
use App\Support\Http\Problem;
use App\Support\Identity\EmailVerifier;
use App\Support\Identity\UserLookup;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Workspaces\Domain\OrganizationRole;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\CustomRole;
use Modules\Workspaces\Infrastructure\Models\Invitation;
use Modules\Workspaces\Infrastructure\Models\OrganizationMember;
use Modules\Workspaces\Infrastructure\Models\Workspace;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;
use Modules\Workspaces\Infrastructure\Notifications\WorkspaceInvitationMail;

/**
 * Team invitations (#36). Rules, here so every client gets them:
 *  - inviting needs workspace.members.manage, and nobody offers a role with more permissions than they have;
 *    only an owner invites an owner;
 *  - one open invitation per email per workspace: inviting again replaces it (a new link, the old one stops working);
 *  - a link works once, for 7 days, only for the address it was sent to, and only while the person who sent it
 *    can still manage the team;
 *  - opening it proves the address, so it also verifies the account;
 *  - joining a workspace also puts the person in its organization (as a member: no organization powers).
 */
final class Invitations
{
    public const int TTL_DAYS = 7;

    public const int MAX_OPEN = 100;

    public function __construct(
        private readonly TenantContext $context,
        private readonly PermissionRegistry $registry,
        private readonly WorkspacePermissions $permissions,
        private readonly UserLookup $users,
        private readonly EmailVerifier $verifier,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Must run inside the workspace. Returns the invitation and its link (shown once: only its hash is kept).
     *
     * @return array{0: Invitation, 1: string}
     */
    public function invite(string $workspaceId, string $actorId, string $email, WorkspaceRole $role, ?string $customRoleId): array
    {
        $email = mb_strtolower(trim($email));
        $token = self::newToken();

        $invitation = DB::transaction(function () use ($workspaceId, $actorId, $email, $role, $customRoleId, $token): Invitation {
            $actor = WorkspaceMember::query()->where('workspace_id', $workspaceId)->where('user_id', $actorId)->first()
                ?? throw Problem::forbidden('not-a-member', 'You are not a member of this workspace.');

            $customRoleId = $this->checkRole($workspaceId, $actor, $role, $customRoleId);

            $existing = $this->users->findByEmail($email);
            if ($existing !== null && WorkspaceMember::query()->where('workspace_id', $workspaceId)->where('user_id', $existing)->exists()) {
                throw Problem::conflict('already-a-member', 'This person is already in the workspace. Change their role instead.');
            }

            // inviting the same address again replaces the open invitation (and its link)
            $replaced = Invitation::query()->where('workspace_id', $workspaceId)->where('email', $email)
                ->whereNull('accepted_at')->whereNull('revoked_at')->lockForUpdate()->first();
            $replaced?->forceFill(['revoked_at' => now()])->save();

            $open = Invitation::query()->where('workspace_id', $workspaceId)->whereNull('accepted_at')->whereNull('revoked_at')
                ->where('expires_at', '>', now())->count();
            if ($open >= self::MAX_OPEN) {
                throw Problem::forbidden('invitation-limit', 'This workspace has '.self::MAX_OPEN.' open invitations. Withdraw some first.');
            }

            $invitation = Invitation::query()->create([
                'workspace_id' => $workspaceId,
                'email' => $email,
                'role' => $role,
                'custom_role_id' => $customRoleId,
                'token_hash' => self::hash($token),
                'invited_by' => $actorId,
                'expires_at' => now()->addDays(self::TTL_DAYS),
            ]);

            $this->audit->workspace($workspaceId, $replaced !== null ? 'member.reinvited' : 'member.invited', $actorId, data: [
                'email' => $email, 'role' => $role->value, 'custom_role_id' => $customRoleId,
            ]);

            return $invitation;
        });

        $workspace = Workspace::query()->findOrFail($workspaceId);
        $inviter = $this->users->find([$actorId])[$actorId]['name'] ?? '';
        Notification::route('mail', $email)->notify(new WorkspaceInvitationMail($workspace->name, $inviter, self::url($token), $invitation->expires_at));

        return [$invitation, self::url($token)];
    }

    /** Must run inside the workspace. */
    public function revoke(string $workspaceId, string $actorId, string $invitationId): void
    {
        DB::transaction(function () use ($workspaceId, $actorId, $invitationId): void {
            $invitation = Invitation::query()->where('workspace_id', $workspaceId)->whereKey($invitationId)->lockForUpdate()->first()
                ?? throw Problem::notFound('invitation-not-found', 'No such invitation.');

            if ($invitation->status() !== 'pending' && $invitation->status() !== 'expired') {
                throw Problem::conflict('invitation-closed', 'This invitation was already accepted or withdrawn.');
            }

            $actor = WorkspaceMember::query()->where('workspace_id', $workspaceId)->where('user_id', $actorId)->firstOrFail();
            if ($invitation->role === WorkspaceRole::Owner && $actor->role !== WorkspaceRole::Owner) {
                throw Problem::forbidden('owner-only', 'Only an owner can withdraw an invitation to be an owner.');
            }

            $invitation->forceFill(['revoked_at' => now()])->save();
            $this->audit->workspace($workspaceId, 'member.invitation_revoked', $actorId, data: ['email' => $invitation->email]);
        });
    }

    /**
     * What the link's page shows. Any status: the page explains an expired or used link.
     *
     * @return array{invitation: Invitation, workspace: string, inviter: string|null, custom_role: string|null}
     */
    public function preview(string $token): array
    {
        [$workspaceId, $invitationId] = $this->locate($token);

        return $this->context->run($workspaceId, function () use ($invitationId): array {
            $invitation = Invitation::query()->with('customRole')->findOrFail($invitationId);
            $inviter = $invitation->invited_by !== null ? ($this->users->find([$invitation->invited_by])[$invitation->invited_by]['name'] ?? null) : null;

            return [
                'invitation' => $invitation,
                'workspace' => Workspace::query()->findOrFail($invitation->workspace_id)->name,
                'inviter' => $inviter,
                'custom_role' => $invitation->customRole?->name,
            ];
        });
    }

    /** Joins the workspace the link is for. Returns the workspace id. Idempotent for someone already in it. */
    public function accept(string $token, string $userId): string
    {
        [$workspaceId, $invitationId] = $this->locate($token);
        $email = mb_strtolower($this->users->find([$userId])[$userId]['email'] ?? '');

        $this->context->run($workspaceId, function () use ($workspaceId, $invitationId, $userId, $email): void {
            DB::transaction(function () use ($workspaceId, $invitationId, $userId, $email): void {
                $invitation = Invitation::query()->whereKey($invitationId)->lockForUpdate()->firstOrFail();

                match ($invitation->status()) {
                    'accepted' => throw Problem::conflict('invitation-used', 'This invitation was already used.'),
                    'revoked' => throw Problem::conflict('invitation-revoked', 'This invitation was withdrawn. Ask for a new one.'),
                    'expired' => throw Problem::conflict('invitation-expired', 'This invitation has expired. Ask for a new one.'),
                    default => null,
                };

                if ($email === '' || ! hash_equals($invitation->email, $email)) {
                    throw Problem::forbidden('invitation-other-email', 'This invitation was sent to another email address. Sign in with that address.');
                }

                if (! $this->inviterCanStillManage($workspaceId, $invitation->invited_by)) {
                    throw Problem::conflict('invitation-revoked', 'This invitation is no longer valid. Ask for a new one.');
                }

                $workspace = Workspace::query()->findOrFail($workspaceId);
                $alreadyIn = WorkspaceMember::query()->where('workspace_id', $workspaceId)->where('user_id', $userId)->exists();

                if (! $alreadyIn) {
                    WorkspaceMember::query()->create([
                        'workspace_id' => $workspaceId,
                        'user_id' => $userId,
                        'role' => $invitation->role,
                        'custom_role_id' => $invitation->custom_role_id,
                    ]);
                    OrganizationMember::query()->firstOrCreate(
                        ['organization_id' => $workspace->organization_id, 'user_id' => $userId],
                        ['role' => OrganizationRole::Member],
                    );
                }

                $invitation->forceFill(['accepted_at' => now(), 'accepted_by' => $userId])->save();

                $data = ['role' => $invitation->role->value, 'custom_role_id' => $invitation->custom_role_id, 'via' => 'invitation', 'invited_by' => $invitation->invited_by];
                if (! $alreadyIn) {
                    $this->audit->workspace($workspaceId, 'member.joined', $userId, $userId, $data);
                    $this->audit->organization($workspace->organization_id, 'workspace.member.added', $userId, $userId, $workspaceId, $data);
                }
            });
        });

        // the link went to this address: it is proven
        $this->verifier->markVerified($userId);

        return $workspaceId;
    }

    /** Whether the link is open for this address (sign-up with an invitation). */
    public function isOpenFor(string $token, string $email): bool
    {
        try {
            $preview = $this->preview($token);
        } catch (Problem) {
            return false;
        }

        return $preview['invitation']->status() === 'pending' && hash_equals($preview['invitation']->email, mb_strtolower(trim($email)));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/invite/'.$token;
    }

    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: string} workspace id, invitation id */
    private function locate(string $token): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            throw Problem::notFound('invitation-not-found', 'This invitation link is not valid.');
        }

        $row = DB::selectOne('select workspace_id, invitation_id from workspaces_invitation_by_token(?)', [self::hash($token)]);

        if ($row === null) {
            throw Problem::notFound('invitation-not-found', 'This invitation link is not valid.');
        }

        return [(string) $row->workspace_id, (string) $row->invitation_id];
    }

    /** The grant ceiling, the same as for changing a role (ChangeMemberRole). Returns the custom role id to store. */
    private function checkRole(string $workspaceId, WorkspaceMember $actor, WorkspaceRole $role, ?string $customRoleId): ?string
    {
        if ($role === WorkspaceRole::Owner && $actor->role !== WorkspaceRole::Owner) {
            throw Problem::forbidden('owner-only', 'Only an owner can invite someone as an owner.');
        }

        $grants = $role->grants();

        if ($role === WorkspaceRole::Custom) {
            $custom = $customRoleId !== null ? CustomRole::query()->where('workspace_id', $workspaceId)->find($customRoleId) : null;

            if ($custom === null) {
                throw Problem::invalid(['custom_role_id' => ['Pick one of this workspace\'s custom roles.']]);
            }

            $grants = array_values($custom->permissions);
        } else {
            $customRoleId = null;
        }

        if ($actor->role !== WorkspaceRole::Owner) {
            $mine = $this->registry->expand($this->permissions->access($workspaceId, $actor->user_id)->grants ?? []);

            if (array_diff($this->registry->expand($grants), $mine) !== []) {
                throw Problem::forbidden('beyond-own-permissions', 'You can only invite people with roles within your own permissions.');
            }
        }

        return $customRoleId;
    }

    private function inviterCanStillManage(string $workspaceId, ?string $inviterId): bool
    {
        if ($inviterId === null) {
            return false;
        }

        $grants = $this->permissions->access($workspaceId, $inviterId)?->grants;

        return $grants !== null && in_array('workspace.members.manage', $this->registry->expand($grants), true);
    }
}
