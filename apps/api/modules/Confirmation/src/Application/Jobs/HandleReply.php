<?php

declare(strict_types=1);

namespace Modules\Confirmation\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Modules\Confirmation\Application\ConfirmationEngine;
use Modules\Confirmation\Domain\ReplyAction;

/** A button reply, handled on the queue (retried on its own, never blocking the webhook drain). */
final class HandleReply implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly string $runId, public readonly ReplyAction $action, public readonly string $fromPhone) {}

    public function handle(ConfirmationEngine $engine): void
    {
        $engine->reply($this->runId, $this->action, $this->fromPhone);
    }
}
