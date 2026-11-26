<?php

declare(strict_types=1);

namespace Modules\Workspaces\Http\Controllers;

use App\Support\Http\Problem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Workspaces\Application\CreateWorkspace;
use Modules\Workspaces\Application\WorkspaceData;
use Modules\Workspaces\Application\WorkspaceDirectory;
use Modules\Workspaces\Domain\WorkspaceRole;

final class WorkspaceController
{
    public function index(Request $request, WorkspaceDirectory $directory): JsonResponse
    {
        $workspaces = $directory->forUser($this->userId($request));

        return response()->json(['data' => array_map(fn (WorkspaceData $w): array => $w->toArray(), $workspaces)]);
    }

    public function store(Request $request, CreateWorkspace $create, WorkspaceDirectory $directory): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $owned = array_filter($directory->forUser($this->userId($request)), fn (WorkspaceData $w): bool => $w->role === WorkspaceRole::Owner);

        if (count($owned) >= (int) config('trik.max_owned_workspaces', 10)) {
            throw Problem::forbidden('workspace-limit', 'You already own the maximum number of workspaces. Contact support to raise it.');
        }

        return response()->json(['data' => $create->handle($data['name'], $this->userId($request))->toArray()], 201);
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
