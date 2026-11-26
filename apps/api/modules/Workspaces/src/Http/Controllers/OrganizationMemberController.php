<?php

declare(strict_types=1);

namespace Modules\Workspaces\Http\Controllers;

use App\Support\Http\CursorPage;
use App\Support\Identity\UserLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Workspaces\Application\ActiveOrganization;
use Modules\Workspaces\Application\OrganizationTeam;
use Modules\Workspaces\Domain\OrganizationRole;
use Modules\Workspaces\Infrastructure\Models\OrganizationMember;
use Modules\Workspaces\Infrastructure\Models\Workspace;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

final class OrganizationMemberController
{
    /** The organization's people, with the workspaces of this organization each one is in. */
    public function index(Request $request, ActiveOrganization $active, UserLookup $users): JsonResponse
    {
        $orgId = $active->id();
        $page = OrganizationMember::query()
            ->where('organization_id', $orgId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursorPaginate(CursorPage::perPage($request), cursor: CursorPage::cursor($request, ['created_at' => 'datetime', 'id' => 'uuid']));

        $userIds = array_values(array_map(fn (OrganizationMember $m): string => $m->user_id, $page->items()));
        $people = $users->find($userIds);
        $names = Workspace::query()->where('organization_id', $orgId)->pluck('name', 'id');
        $memberships = WorkspaceMember::query()->whereIn('user_id', $userIds)->whereIn('workspace_id', $names->keys())->get()->groupBy('user_id');

        return CursorPage::response($page, fn (OrganizationMember $m): array => [
            'user_id' => $m->user_id,
            'name' => $people[$m->user_id]['name'] ?? null,
            'email' => $people[$m->user_id]['email'] ?? null,
            'role' => $m->role->value,
            'workspaces' => ($memberships->get($m->user_id) ?? collect())->map(fn (WorkspaceMember $w): array => [
                'id' => $w->workspace_id, 'name' => $names->get($w->workspace_id), 'role' => $w->role->value,
            ])->values()->all(),
        ]);
    }

    public function update(Request $request, string $user, ActiveOrganization $active, OrganizationTeam $team): JsonResponse
    {
        $data = $request->validate(['role' => ['required', Rule::enum(OrganizationRole::class)]]);
        $member = $team->changeRole($active->id(), $this->actor($request), $user, OrganizationRole::from($data['role']));

        return response()->json(['data' => ['user_id' => $member->user_id, 'role' => $member->role->value]]);
    }

    public function destroy(Request $request, string $user, ActiveOrganization $active, OrganizationTeam $team): JsonResponse
    {
        if ($user !== $this->actor($request)) {
            Gate::authorize('organization.members.manage'); // anyone may leave
        }

        $team->remove($active->id(), $this->actor($request), $user);

        return response()->json(null, 204);
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
