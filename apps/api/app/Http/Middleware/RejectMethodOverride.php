<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Http\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API uses real HTTP methods only. Method spoofing (`X-HTTP-Method-Override`, `_method`)
 * would let a POST act as DELETE past anything that looked at the wire method.
 */
final class RejectMethodOverride
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->headers->has('X-HTTP-Method-Override') || $request->request->has('_method') || $request->query->has('_method')) {
            throw Problem::badRequest('method-override-not-allowed', 'Send the real HTTP method; method override is not supported.');
        }

        return $next($request);
    }
}
