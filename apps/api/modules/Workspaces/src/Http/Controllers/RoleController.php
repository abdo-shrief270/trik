<?php

declare(strict_types=1);

namespace Modules\Workspaces\Http\Controllers;

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

    public function store(Request $request, ManageRoles $roles): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'max:100'],
        ]);

        return response()->json(['data' => $roles->create($data['name'], $data['description'] ?? null, array_values($data['permissions']))->toApi()], 201);
    }

    public function update(Request $request, string $role, ManageRoles $roles): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array', 'min:1'],
            'permissions.*' => ['string', 'max:100'],
        ]);

        $updated = $roles->update(
            CustomRole::query()->findOrFail($role),
            $data['name'] ?? null,
            $data['description'] ?? null,
            isset($data['permissions']) ? array_values($data['permissions']) : null,
        );

        return response()->json(['data' => $updated->toApi()]);
    }

    public function destroy(string $role, ManageRoles $roles): JsonResponse
    {
        $roles->delete(CustomRole::query()->findOrFail($role));

        return response()->json(null, 204);
    }
}
