<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Http\Problem;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Features that reach customers (WhatsApp numbers, sending) need a verified email: stops throwaway sign-ups. */
final class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            throw Problem::forbidden('email-not-verified', 'Verify your email address first. Check your inbox or ask for a new link.');
        }

        return $next($request);
    }
}
