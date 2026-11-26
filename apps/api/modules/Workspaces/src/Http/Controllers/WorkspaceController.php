<?php

declare(strict_types=1);

namespace Modules\Workspaces\Http\Controllers;

use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Workspaces\Application\CreateWorkspace;
use Modules\Workspaces\Application\OrganizationData;
use Modules\Workspaces\Application\WorkspaceData;
use Modules\Workspaces\Application\WorkspaceDirectory;

final class WorkspaceController
{
    public function index(Request $request, WorkspaceDirectory $directory): JsonResponse
    {
        $workspaces = $directory->forUser($this->userId($request));

        return response()->json(['data' => array_map(fn (WorkspaceData $w): array => $w->toArray(), $workspaces)]);
    }

    public function store(Request $request, CreateWorkspace $create): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'organization_id' => ['nullable', 'uuid'],
        ]);

        return response()->json(['data' => $create->forUser($data['name'], $this->userId($request), $data['organization_id'] ?? null)->toArray()], 201);
    }

    /** The user's organizations with only their workspaces (the switcher). */
    public function organizations(Request $request, WorkspaceDirectory $directory): JsonResponse
    {
        return response()->json(['data' => array_map(fn (OrganizationData $o): array => $o->toArray(), $directory->organizationsFor($this->userId($request)))]);
    }

    /** The workspace named in X-Workspace-Id (requires the workspace middleware). */
    public function current(Request $request, TenantContext $context, WorkspaceDirectory $directory): JsonResponse
    {
        $workspace = $directory->find($context->require(), $this->userId($request));

        abort_if($workspace === null, 404);

        return response()->json(['data' => $workspace->toArray()]);
    }

    private function userId(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
