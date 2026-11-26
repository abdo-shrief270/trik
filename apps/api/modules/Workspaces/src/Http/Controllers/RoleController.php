<?php

declare(strict_types=1);

namespace Modules\Workspaces\Http\Controllers;

use App\Support\Authorization\MemberAccess;
use App\Support\Authorization\PermissionGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Workspaces\Application\ManageRoles;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\CustomRole;

final class RoleController
{
    /** Built-in roles first, then the workspace's custom roles. */
    public function index(): JsonResponse
    {
        $builtIn = array_map(fn (WorkspaceRole $r): array => [
            'id' => $r->value, 'name' => $r->value, 'description' => null, 'permissions' => $r->grants(), 'built_in' => true,
        ], array_values(array_filter(WorkspaceRole::cases(), fn (WorkspaceRole $r): bool => $r->isBuiltIn())));

        $custom = CustomRole::query()->orderBy('name')->get()->map(fn (CustomRole $r): array => $r->toApi())->all();

        return response()->json(['data' => [...$builtIn, ...$custom]]);
    }

    public function store(Request $request, ManageRoles $roles, PermissionGate $gate): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'max:100'],
        ]);

        return response()->json(['data' => $roles->create($this->actor($request, $gate), $data['name'], $data['description'] ?? null, array_values($data['permissions']))->toApi()], 201);
    }

    public function update(Request $request, string $role, ManageRoles $roles, PermissionGate $gate): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array', 'min:1'],
            'permissions.*' => ['string', 'max:100'],
        ]);

        $updated = $roles->update(
            $this->actor($request, $gate),
            CustomRole::query()->findOrFail($role),
            $data['name'] ?? null,
            $data['description'] ?? null,
            isset($data['permissions']) ? array_values($data['permissions']) : null,
        );

        return response()->json(['data' => $updated->toApi()]);
    }

    public function destroy(Request $request, string $role, ManageRoles $roles, PermissionGate $gate): JsonResponse
    {
        $roles->delete($this->actor($request, $gate), CustomRole::query()->findOrFail($role));

        return response()->json(null, 204);
    }

    private function actor(Request $request, PermissionGate $gate): MemberAccess
    {
        return $gate->current($request->user()) ?? throw new AuthorizationException;
    }
}
