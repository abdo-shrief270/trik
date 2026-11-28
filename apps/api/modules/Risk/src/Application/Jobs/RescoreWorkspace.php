<?php

declare(strict_types=1);

namespace Modules\Risk\Application\Jobs;

use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Orders\Application\OrderReader;

/**
 * Scores a workspace's open orders again: after the seller changes the threshold or weights, or when risk
 * is turned on for them (risk:rescore). The most recent 2,000 open orders; older ones are scored when
 * they next change.
 */
final class RescoreWorkspace implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const LIMIT = 2000;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public readonly string $workspaceId) {}

    public function uniqueId(): string
    {
        return 'risk-rescore:'.$this->workspaceId;
    }

    public function handle(TenantContext $context, OrderReader $orders): void
    {
        $ids = $context->run($this->workspaceId, fn (): array => $orders->openOrderIds(self::LIMIT));

        foreach (array_chunk($ids, 100) as $chunk) {
            ScoreOrders::dispatch($this->workspaceId, $chunk);
        }
    }
}
