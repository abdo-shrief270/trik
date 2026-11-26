<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Modules\Identity\Infrastructure\Models\User;

/**
 * Two ways to sign in, picked per request:
 *  - mobile and API clients send device_name and get a bearer token back;
 *  - the web app (a Sanctum stateful domain) gets a session cookie.
 */
trait StartsSessions
{
    /** @return array{token?: string} */
    private function startSession(Request $request, User $user): array
    {
        if ($request->filled('device_name')) {
            return ['token' => $user->createToken($request->string('device_name')->toString())->plainTextToken];
        }

        if (! EnsureFrontendRequestsAreStateful::fromFrontend($request) || ! $request->hasSession()) {
            throw ValidationException::withMessages(['device_name' => 'device_name is required for token sign-in.']);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return [];
    }
}
