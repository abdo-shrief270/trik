<?php

declare(strict_types=1);

namespace Modules\Workspaces\Http\Controllers;

use App\Support\Http\Problem;
use App\Support\Identity\UserLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Workspaces\Application\ActiveOrganization;
use Modules\Workspaces\Application\OrganizationTeam;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\Workspace;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

/** Organization owners/admins manage who is in each workspace (audited, visible to the workspace). */
final class OrganizationWorkspaceMemberController
{
    public function index(string $workspace, ActiveOrganization $active, UserLookup $users): JsonResponse
    {
        $this->assertInOrganization($workspace, $active);
        $members = WorkspaceMember::query()->where('workspace_id', $workspace)->orderBy('created_at')->get();
        $people = $users->find(array_values($members->pluck('user_id')->all()));

        return response()->json(['data' => $members->map(fn (WorkspaceMember $m): array => [
            'user_id' => $m->user_id,
            'name' => $people[$m->user_id]['name'] ?? null,
            'role' => $m->role->value,
            'custom_role_id' => $m->custom_role_id,
        ])->values()]);
    }

    public function store(Request $request, string $workspace, ActiveOrganization $active, OrganizationTeam $team): JsonResponse
    {
        $data = $this->validated($request, withUser: true);
        $member = $team->addToWorkspace($active->id(), $this->actor($request), $workspace, $data['user_id'], WorkspaceRole::from($data['role']), $data['custom_role_id'] ?? null);

        return response()->json(['data' => $this->present($member)], 201);
    }

    public function update(Request $request, string $workspace, string $user, ActiveOrganization $active, OrganizationTeam $team): JsonResponse
    {
        $data = $this->validated($request, withUser: false);
        $member = $team->changeWorkspaceRole($active->id(), $this->actor($request), $workspace, $user, WorkspaceRole::from($data['role']), $data['custom_role_id'] ?? null);

        return response()->json(['data' => $this->present($member)]);
    }

    public function destroy(Request $request, string $workspace, string $user, ActiveOrganization $active, OrganizationTeam $team): JsonResponse
    {
        $team->removeFromWorkspace($active->id(), $this->actor($request), $workspace, $user);

        return response()->json(null, 204);
    }

    /** @return array{user_id: string, role: string, custom_role_id?: string|null} */
    private function validated(Request $request, bool $withUser): array
    {
        /** @var array{user_id: string, role: string, custom_role_id?: string|null} */
        return $request->validate(array_filter([
            'user_id' => $withUser ? ['required', 'uuid'] : null,
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
            'custom_role_id' => ['nullable', 'uuid'],
        ]));
    }

    /** @return array{user_id: string, role: string, custom_role_id: string|null} */
    private function present(WorkspaceMember $m): array
    {
        return ['user_id' => $m->user_id, 'role' => $m->role->value, 'custom_role_id' => $m->custom_role_id];
    }

    private function assertInOrganization(string $workspace, ActiveOrganization $active): void
    {
        if (! Workspace::query()->whereKey($workspace)->where('organization_id', $active->id())->exists()) {
            throw Problem::notFound('workspace-not-found', 'This workspace is not part of the organization.');
        }
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
