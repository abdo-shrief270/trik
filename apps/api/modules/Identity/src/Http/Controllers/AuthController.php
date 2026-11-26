<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Identity\Application\RegisterUser;
use Modules\Identity\Http\Controllers\Concerns\StartsSessions;
use Modules\Identity\Http\Requests\LoginRequest;
use Modules\Identity\Http\Requests\RegisterRequest;
use Modules\Identity\Infrastructure\Models\User;
use Modules\Workspaces\Application\WorkspaceData;
use Modules\Workspaces\Application\WorkspaceDirectory;

final class AuthController
{
    use StartsSessions;

    public function register(RegisterRequest $request, RegisterUser $register): JsonResponse
    {
        /** @var array{name: string, email: string, password: string, workspace_name: string} $data */
        $data = $request->validated();

        [$user, $workspace] = $register->handle($data['name'], $data['email'], $data['password'], $data['workspace_name']);

        return response()->json([
            'user' => $this->userPayload($user),
            'workspace' => $workspace->toArray(),
            ...$this->startSession($request, $user),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $request->authenticate();

        return response()->json(['user' => $this->userPayload($user), ...$this->startSession($request, $user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return response()->json(null, 204);
    }

    public function me(Request $request, WorkspaceDirectory $directory): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'user' => $this->userPayload($user),
            'workspaces' => array_map(fn (WorkspaceData $w): array => $w->toArray(), $directory->forUser($user->id)),
        ]);
    }

    /** @return array{id: string, name: string, email: string, email_verified: bool} */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
