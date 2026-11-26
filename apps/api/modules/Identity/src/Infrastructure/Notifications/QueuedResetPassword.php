<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Sent from the queue, so a mail outage never fails or slows the request (see QueuedVerifyEmail). */
final class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(string $token)
    {
        parent::__construct($token);
        $this->afterCommit();
    }
}
