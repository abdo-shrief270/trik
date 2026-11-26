<?php

declare(strict_types=1);

namespace Modules\Workspaces\Http\Controllers;

use App\Support\Http\CursorPage;
use App\Support\Identity\UserLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Workspaces\Application\ActiveOrganization;
use Modules\Workspaces\Application\AuditLog;
use Modules\Workspaces\Infrastructure\Models\Organization;
use Modules\Workspaces\Infrastructure\Models\Workspace;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;
use Modules\Workspaces\Infrastructure\OrganizationPermissions;

final class OrganizationController
{
    public function show(ActiveOrganization $active): JsonResponse
    {
        $org = Organization::query()->findOrFail($active->id());
        $role = $active->role();

        return response()->json(['data' => [
            'id' => $org->id,
            'name' => $org->name,
            'role' => $role?->value,
            'permissions' => OrganizationPermissions::registry()->expand($role?->grants() ?? []),
        ]]);
    }

    public function update(Request $request, ActiveOrganization $active, AuditLog $audit): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $org = Organization::query()->findOrFail($active->id());
        $from = $org->name;
        $org->forceFill(['name' => $data['name']])->save();
        $audit->organization($org->id, 'organization.renamed', (string) $request->user()?->getAuthIdentifier(), data: ['from' => $from, 'to' => $org->name]);

        return response()->json(['data' => ['id' => $org->id, 'name' => $org->name]]);
    }

    /** Names of every workspace in the organization (no data), and whether the caller is in each. */
    public function workspaces(Request $request, ActiveOrganization $active): JsonResponse
    {
        $mine = WorkspaceMember::query()->where('user_id', (string) $request->user()?->getAuthIdentifier())->pluck('role', 'workspace_id');

        $rows = Workspace::query()->where('organization_id', $active->id())->orderBy('created_at')->get(['id', 'name', 'slug']);

        return response()->json(['data' => $rows->map(fn (Workspace $w): array => [
            'id' => $w->id,
            'name' => $w->name,
            'slug' => $w->slug,
            'my_role' => $mine->get($w->id)?->value,
        ])->values()]);
    }

    public function audit(Request $request, ActiveOrganization $active, UserLookup $users): JsonResponse
    {
        $page = DB::table('workspaces_organization_audit_events')
            ->where('organization_id', $active->id())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(CursorPage::perPage($request), cursor: CursorPage::cursor($request, ['created_at' => 'datetime', 'id' => 'uuid']));

        return AuditPresenter::page($page, $users);
    }
}
