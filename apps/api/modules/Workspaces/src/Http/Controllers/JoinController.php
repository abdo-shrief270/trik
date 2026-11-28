<?php

declare(strict_types=1);

namespace Modules\Workspaces\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Workspaces\Application\Invitations;
use Modules\Workspaces\Application\WorkspaceDirectory;

/** The invitation link's page: what it is for (public: the link is the secret), and joining. */
final class JoinController
{
    public function show(string $token, Invitations $invitations): JsonResponse
    {
        $p = $invitations->preview($token);
        $i = $p['invitation'];

        return response()->json(['data' => [
            'workspace' => ['name' => $p['workspace']],
            'inviter' => $p['inviter'] !== null ? ['name' => $p['inviter']] : null,
            'email' => $i->email,
            'role' => $i->role->value,
            'custom_role' => $p['custom_role'],
            'status' => $i->status(),
            'expires_at' => $i->expires_at->toIso8601String(),
        ]])->header('Cache-Control', 'no-store');
    }

    public function accept(Request $request, string $token, Invitations $invitations, WorkspaceDirectory $directory): JsonResponse
    {
        $userId = (string) $request->user()?->getAuthIdentifier();
        $workspaceId = $invitations->accept($token, $userId);

        return response()->json(['data' => ['workspace' => $directory->find($workspaceId, $userId)?->toArray()]]);
    }
}
