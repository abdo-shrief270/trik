<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers;

use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Identity\Infrastructure\Models\User;

/**
 * "Where you're signed in": mobile/API tokens plus browser sessions (when sessions
 * are stored in the database), each of which can be signed out remotely.
 */
final class SessionController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $current = $user->currentAccessToken();
        $currentTokenId = $current instanceof PersonalAccessToken ? $current->getKey() : null;

        $tokens = $user->tokens()->latest('last_used_at')->get()->toBase()->map(fn (PersonalAccessToken $t): array => [
            'type' => 'token',
            'id' => (string) $t->getKey(),
            'name' => (string) $t->getAttribute('name'),
            'last_active_at' => self::iso($t->getAttribute('last_used_at')),
            'created_at' => self::iso($t->getAttribute('created_at')),
            'current' => $t->getKey() === $currentTokenId,
        ]);

        $browsers = collect();
        if (config('session.driver') === 'database') {
            $currentSession = $request->hasSession() ? $request->session()->getId() : null;
            $browsers = DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->orderByDesc('last_activity')
                ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
                ->map(fn (object $s): array => [
                    'type' => 'browser',
                    'id' => (string) $s->id,
                    'name' => (string) $s->user_agent,
                    'ip_address' => $s->ip_address,
                    'last_active_at' => Carbon::createFromTimestamp((int) $s->last_activity)->toIso8601String(),
                    'current' => $s->id === $currentSession,
                ]);
        }

        return response()->json(['data' => $tokens->concat($browsers)->values()]);
    }

    public function destroy(Request $request, string $type, string $id): JsonResponse
    {
        $user = $this->user($request);

        $deleted = match ($type) {
            'token' => $user->tokens()->whereKey($id)->delete(),
            'browser' => DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->where('id', $id)->delete(),
            default => 0,
        };

        abort_if($deleted === 0, 404);

        return response()->json(null, 204);
    }

    private static function iso(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
