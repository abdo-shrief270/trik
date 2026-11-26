<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Sent from the queue, after the sign-up transaction commits: a slow or failing mail
 * server must never fail a sign-up (it did: SMTP down meant a 500 on /auth/register).
 */
final class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct()
    {
        $this->afterCommit();
    }
}
