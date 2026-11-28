<?php

declare(strict_types=1);

namespace Modules\Risk\Application\Jobs;

use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Risk\Application\ScoreOrder;
use Throwable;

/** Scores a few orders of one workspace; one failure doesn't stop the others. */
final class ScoreOrders implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @param list<string> $orderIds */
    public function __construct(public readonly string $workspaceId, public readonly array $orderIds) {}

    public function handle(TenantContext $context, ScoreOrder $score): void
    {
        $context->run($this->workspaceId, function () use ($score): void {
            foreach ($this->orderIds as $id) {
                try {
                    $score->handle($id);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });
    }
}
