<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Http\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses oversized bodies before any work (also set at the edge: Caddy `request_body max_size`).
 * Webhooks get a tighter limit than the app API.
 */
final class LimitRequestSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) ($request->is('webhooks/*')
            ? config('trik.max_body_bytes.webhooks', 1_048_576)
            : config('trik.max_body_bytes.api', 2_097_152));

        $declared = (int) $request->headers->get('Content-Length', '0');

        if ($declared > $limit || strlen($request->getContent()) > $limit) {
            throw Problem::make(413, 'payload-too-large', "The request body is larger than {$limit} bytes.");
        }

        return $next($request);
    }
}
