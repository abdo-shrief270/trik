<?php

declare(strict_types=1);

namespace App\Support\Identity;

/**
 * Implemented by the Identity module. Lets another module record that a person proved their email address some other
 * way, e.g. by opening a team invitation sent to it.
 */
interface EmailVerifier
{
    public function markVerified(string $userId): void;
}
