<?php

declare(strict_types=1);

namespace App\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id (X-Request-Id, kept if a trusted proxy set one), returns it in
 * the response, adds it to logs and to error bodies, so a seller's error can be found in logs.
 */
final class RequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = self::of($request);
        Log::withContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    public static function of(Request $request): string
    {
        $id = $request->attributes->get('request_id');

        if (! is_string($id)) {
            $incoming = (string) $request->headers->get(self::HEADER, '');
            $id = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) === 1 ? $incoming : (string) Str::uuid7();
            $request->attributes->set('request_id', $id);
        }

        return $id;
    }
}
