<?php

declare(strict_types=1);

namespace Modules\Workspaces\Http\Controllers;

use App\Support\Authorization\PermissionGate;
use App\Support\Authorization\PermissionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PermissionController
{
    /** What the signed-in member can do here. Clients use it to show or hide actions. */
    public function mine(Request $request, PermissionGate $gate, PermissionRegistry $registry): JsonResponse
    {
        $access = $gate->current($request->user());

        return response()->json(['data' => [
            'role' => $access?->role,
            'custom_role_id' => $access?->customRoleId,
            'permissions' => $registry->expand($access->grants ?? []),
        ]]);
    }

    /** Every permission, grouped by module, for the role editor. */
    public function catalog(PermissionRegistry $registry): JsonResponse
    {
        $groups = [];
        foreach ($registry->all() as $key => $meta) {
            $groups[$meta['module']][] = ['key' => $key, 'description' => $meta['description']];
        }

        return response()->json(['data' => array_map(
            fn (string $module, array $permissions): array => ['module' => $module, 'permissions' => $permissions],
            array_keys($groups),
            array_values($groups),
        )]);
    }
}
