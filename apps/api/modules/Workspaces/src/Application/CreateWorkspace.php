<?php

declare(strict_types=1);

namespace Modules\Workspaces\Application;

use App\Support\Http\Problem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Workspaces\Domain\OrganizationRole;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\Organization;
use Modules\Workspaces\Infrastructure\Models\OrganizationMember;
use Modules\Workspaces\Infrastructure\Models\Workspace;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

/**
 * Creates a workspace inside an organization and makes the creator its workspace owner
 * (and a member of the organization's people list if they weren't yet).
 */
final class CreateWorkspace
{
    public function __construct(
        private readonly CreateOrganization $createOrganization,
        private readonly AuditLog $audit,
    ) {}

    public function handle(string $name, string $ownerId, string $organizationId): WorkspaceData
    {
        return DB::transaction(function () use ($name, $ownerId, $organizationId): WorkspaceData {
            $limit = (int) config('trik.max_workspaces_per_organization', 10);

            // lock the organization row: two creations at once can't both pass the limit
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();

            if (Workspace::query()->where('organization_id', $organizationId)->count() >= $limit) {
                throw Problem::forbidden('workspace-limit', "This organization already has {$limit} workspaces. Contact support to raise the limit.");
            }

            $workspace = Workspace::query()->create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'organization_id' => $organizationId,
            ]);

            WorkspaceMember::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $ownerId,
                'role' => WorkspaceRole::Owner,
            ]);

            OrganizationMember::query()->firstOrCreate(
                ['organization_id' => $organizationId, 'user_id' => $ownerId],
                ['role' => OrganizationRole::Member],
            );

            $this->audit->organization($organizationId, 'workspace.created', $ownerId, workspaceId: $workspace->id, data: ['name' => $name]);
            $this->audit->workspace($workspace->id, 'workspace.created', $ownerId, data: ['name' => $name]);

            return new WorkspaceData($workspace->id, $workspace->name, $workspace->slug, WorkspaceRole::Owner, $organizationId);
        });
    }

    /**
     * Where a user's new workspace goes:
     *  - the organization they name, if they are its owner or admin;
     *  - otherwise the one organization they own or administer;
     *  - otherwise (e.g. an agent in someone else's organization) a new organization of their own.
     */
    public function forUser(string $name, string $userId, ?string $organizationId = null): WorkspaceData
    {
        $manageable = OrganizationMember::query()
            ->where('user_id', $userId)
            ->whereIn('role', [OrganizationRole::Owner, OrganizationRole::Admin])
            ->pluck('organization_id')
            ->all();

        if ($organizationId !== null) {
            if (! in_array($organizationId, $manageable, true)) {
                throw Problem::forbidden('organization-forbidden', 'Only owners and admins of an organization can add workspaces to it.');
            }

            return $this->handle($name, $userId, $organizationId);
        }

        return match (count($manageable)) {
            0 => DB::transaction(fn (): WorkspaceData => $this->handle($name, $userId, $this->createOrganization->handle($name, $userId)->id)),
            1 => $this->handle($name, $userId, (string) $manageable[0]),
            default => throw Problem::invalid(['organization_id' => ['You manage several organizations: say which one this workspace belongs to.']]),
        };
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;

        while (Workspace::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
