<?php

declare(strict_types=1);

namespace App\Support\Http;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Safe retries for writes. A client that sends `Idempotency-Key` on POST/PUT/PATCH/DELETE gets
 * the first response replayed for any retry with the same key (24 h), so a flaky mobile network
 * never confirms an order or sends a message twice.
 *
 *  - Only for signed-in callers. Keys are scoped to the caller, workspace, method and path.
 *  - The same key with a different body is refused (422); a retry while the first is still
 *    running is refused (409). Server errors (5xx) are not stored, so they can be retried.
 *  - Stored in the cache store (Redis in production).
 *
 * Use the `idempotency.required` middleware on routes where a missing key is an error.
 */
final class Idempotency
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    public const TTL_SECONDS = 86400;

    private const METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly CacheFactory $cache) {}

    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $key = (string) $request->headers->get(self::HEADER, '');

        // Registered on the api group and again as `idempotency:required` on some routes: work once.
        if ($request->attributes->get('idempotency.handled') === true) {
            return $next($request);
        }

        if ($key === '' || ! in_array($request->method(), self::METHODS, true)) {
            if ($mode === 'required' && in_array($request->method(), self::METHODS, true)) {
                throw Problem::badRequest('idempotency-key-required', 'Send an '.self::HEADER.' header (a UUID per action) with this request.');
            }

            return $next($request);
        }

        // Only signed-in callers: guest responses (sign-in, sign-up) carry tokens and are never cached.
        if (Auth::guard('sanctum')->user() === null && $request->user() === null) {
            return $next($request);
        }

        if (strlen($key) > 255 || preg_match('/^[\x21-\x7E]+$/', $key) !== 1) {
            throw Problem::badRequest('idempotency-key-invalid', self::HEADER.' must be 1 to 255 visible ASCII characters.');
        }

        $request->attributes->set('idempotency.handled', true);
        $store = $this->store();
        $slot = 'idempotency:'.hash('sha256', implode('|', [$this->caller($request), $request->header('X-Workspace-Id', '-'), $request->method(), $request->path(), $key]));
        $fingerprint = hash('sha256', $request->getContent());

        if (($saved = $this->saved($store, $slot)) !== null) {
            return $this->replay($saved, $fingerprint);
        }

        $locks = $store->getStore();

        if (! $locks instanceof LockProvider) {
            throw new \RuntimeException('Idempotency-Key needs a cache store that supports locks (Redis in production).');
        }

        $lock = $locks->lock($slot.':lock', 60);

        if (! $lock->get()) {
            throw Problem::conflict('idempotency-in-progress', 'A request with this '.self::HEADER.' is still being processed. Retry shortly.');
        }

        try {
            // another attempt may have finished while we waited for the lock
            if (($saved = $this->saved($store, $slot)) !== null) {
                return $this->replay($saved, $fingerprint);
            }

            $response = $next($request);

            if ($response->getStatusCode() < 500) {
                $store->put($slot, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'content_type' => (string) $response->headers->get('Content-Type', 'application/json'),
                    'body' => (string) $response->getContent(),
                ], self::TTL_SECONDS);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * Reads the stored response. Impure: another attempt may store it meanwhile.
     *
     * @phpstan-impure
     */
    private function saved(Repository $store, string $slot): mixed
    {
        return $store->get($slot);
    }

    private function replay(mixed $saved, string $fingerprint): Response
    {
        if (! is_array($saved) || ($saved['fingerprint'] ?? null) !== $fingerprint) {
            throw Problem::unprocessable('idempotency-key-reused', 'This '.self::HEADER.' was already used for a different request.');
        }

        return new Response((string) $saved['body'], (int) $saved['status'], [
            'Content-Type' => (string) $saved['content_type'],
            self::REPLAYED_HEADER => 'true',
        ]);
    }

    private function caller(Request $request): string
    {
        $user = Auth::guard('sanctum')->user() ?? $request->user();

        return 'user:'.$user?->getAuthIdentifier();
    }

    private function store(): Repository
    {
        $name = config('trik.idempotency_store');

        return $this->cache->store(is_string($name) && $name !== '' ? $name : null);
    }
}
