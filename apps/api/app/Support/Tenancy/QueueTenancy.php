<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\QueueManager;

/**
 * Carries the active workspace into queued jobs and restores it when they run.
 * Uses a stack so a sync-dispatched job never clears the dispatcher's own context.
 */
final class QueueTenancy
{
    public const PAYLOAD_KEY = 'workspace_id';

    /** @var list<string|null> */
    private array $stack = [];

    public function __construct(private readonly TenantContext $context) {}

    public function register(QueueManager $queue, Dispatcher $events): void
    {
        $queue->createPayloadUsing(fn (): array => $this->context->has()
            ? [self::PAYLOAD_KEY => $this->context->id()]
            : []);

        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->stack[] = $this->context->id();
            $id = $event->job->payload()[self::PAYLOAD_KEY] ?? null;
            is_string($id) ? $this->context->set($id) : $this->context->forget();
        });

        $events->listen([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class], function (): void {
            if ($this->stack === []) {
                return;
            }
            $previous = array_pop($this->stack);
            $previous === null ? $this->context->forget() : $this->context->set($previous);
        });
    }
}
