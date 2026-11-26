<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Turns any exception on the API into an RFC 9457 problem+json response. */
final class ProblemRenderer
{
    public const CONTENT_TYPE = 'application/problem+json';

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        $problem = self::toProblem($e);

        return new JsonResponse(
            $problem->toArray('/'.ltrim($request->path(), '/'), RequestId::of($request)),
            $problem->status,
            ['Content-Type' => self::CONTENT_TYPE, ...$problem->headers],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    public static function toProblem(Throwable $e): Problem
    {
        // Laravel wraps some exceptions in HTTP ones before render callbacks run; use the original.
        if ($e instanceof HttpExceptionInterface && ($e->getPrevious() instanceof AuthorizationException || $e->getPrevious() instanceof ModelNotFoundException)) {
            $e = $e->getPrevious();
        }

        return match (true) {
            $e instanceof Problem => $e,
            $e instanceof ValidationException => new Problem(
                $e->status,
                $e->status === 429 ? 'too-many-attempts' : 'validation-failed',
                $e->status === 429 ? 'Too Many Requests' : 'Unprocessable Content',
                $e->getMessage(),
                ['errors' => $e->errors()],
            ),
            $e instanceof AuthenticationException => Problem::make(401, 'unauthenticated', 'Sign in to continue.'),
            $e instanceof AuthorizationException => Problem::make(
                403,
                is_string($code = $e->response()?->code()) && $code !== '' ? $code : 'forbidden',
                $e->getMessage() ?: 'You are not allowed to do this.',
            ),
            $e instanceof ModelNotFoundException => Problem::make(404, 'not-found', 'The resource does not exist.'),
            $e instanceof TokenMismatchException => Problem::make(419, 'csrf-token-mismatch', 'Refresh the page and try again.'),
            $e instanceof ThrottleRequestsException => new Problem(429, 'rate-limited', 'Too Many Requests', 'Slow down and retry later.', [
                'retry_after' => (int) ($e->getHeaders()['Retry-After'] ?? 60),
            ], $e->getHeaders()),
            $e instanceof HttpExceptionInterface => new Problem(
                $e->getStatusCode(),
                self::slug($e->getStatusCode()),
                Response::$statusTexts[$e->getStatusCode()] ?? 'Error',
                $e->getMessage() !== '' ? $e->getMessage() : null,
                [],
                $e->getHeaders(),
            ),
            default => Problem::make(500, 'internal-error', config('app.debug') ? $e->getMessage() : 'Something went wrong on our side. It has been logged.'),
        };
    }

    private static function slug(int $status): string
    {
        return match ($status) {
            400 => 'bad-request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not-found',
            405 => 'method-not-allowed',
            409 => 'conflict',
            422 => 'unprocessable',
            429 => 'rate-limited',
            503 => 'unavailable',
            default => 'http-'.$status,
        };
    }
}
