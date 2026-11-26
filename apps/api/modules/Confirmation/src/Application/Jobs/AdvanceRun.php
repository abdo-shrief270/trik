<?php

declare(strict_types=1);

namespace Modules\Confirmation\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Modules\Confirmation\Application\ConfirmationEngine;

/** One step of a run. Unique per run, so the minute scheduler never queues it twice. */
final class AdvanceRun implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $runId) {}

    public function uniqueId(): string
    {
        return 'confirmation-run:'.$this->runId;
    }

    public function handle(ConfirmationEngine $engine): void
    {
        $engine->advance($this->runId);
    }
}
