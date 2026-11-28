<?php

declare(strict_types=1);

namespace Modules\Risk\Application;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Addresses\Domain\Address;
use Modules\Orders\Application\OrderHistory;
use Modules\Orders\Application\OrderReader;
use Modules\Orders\Application\OrderTimeline;
use Modules\Orders\Domain\OrderStatus;
use Modules\Risk\Domain\RiskInput;
use Modules\Risk\Domain\RiskScore;
use Modules\Risk\Domain\RiskScorer;
use Modules\Risk\Infrastructure\Models\RiskAssessment;
use Modules\Risk\Infrastructure\Models\RiskSettings;

/**
 * Scores one order (inside its workspace: every number comes from this seller's own orders), keeps the
 * assessment with its reasons and inputs, writes the score on the order for the board, and notes on the
 * order timeline the first time it reaches the seller's threshold. Finished orders keep their last score.
 */
final class ScoreOrder
{
    public const TIMEZONE = 'Africa/Cairo';

    public function __construct(
        private readonly OrderReader $orders,
        private readonly OrderHistory $history,
        private readonly RiskScorer $scorer,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(string $orderId): ?RiskScore
    {
        $order = $this->orders->find($orderId);
        if ($order === null || in_array($order->status, [OrderStatus::Delivered, OrderStatus::Returned, OrderStatus::Cancelled], true)) {
            return null;
        }

        $past = $this->history->customer($order->customerPhone, $order->id);
        $usual = $this->history->codMedian($this->tenant->require());
        $address = Address::fromArray($order->address);
        $settings = RiskSettings::current();

        $input = new RiskInput(
            previousOrders: $past->orders,
            delivered: $past->delivered,
            refused: $past->refused,
            cancelled: $past->cancelled,
            unreachable: $past->unreachable,
            codAmount: $order->isCod() ? $order->total : 0,
            prepaid: ! $order->isCod(),
            usualCodAmount: $usual['median'],
            usualSample: $usual['sample'],
            governorateKnown: $address->governorate !== null,
            addressDeliverable: $address->isDeliverable(),
            addressFlagged: $order->addressNeedsFix,
            hour: (int) Carbon::instance($order->placedAt ?? now())->setTimezone(self::TIMEZONE)->format('G'),
        );
        $score = $this->scorer->score($input, $settings->riskWeights());

        DB::transaction(function () use ($order, $score, $input, $settings): void {
            /** @var RiskAssessment|null $previous */
            $previous = RiskAssessment::query()->lockForUpdate()->find($order->id);
            $flagged = $score->score >= $settings->threshold;
            $firstFlag = $flagged && $previous?->flagged_at === null;

            RiskAssessment::query()->updateOrCreate(['order_id' => $order->id], [
                'score' => $score->score,
                'reasons' => $score->reasons,
                'inputs' => $input->toArray(),
                'model' => RiskScore::MODEL,
                'flagged_at' => $previous->flagged_at ?? ($flagged ? now() : null),
                'computed_at' => now(),
            ]);
            $this->history->recordRisk($order->id, $score->score);

            if ($firstFlag) {
                OrderTimeline::add($order->id, 'risk_flagged', null, [
                    'score' => $score->score, 'threshold' => $settings->threshold, 'reasons' => array_column($score->top(), 'code'),
                ]);
            }
        });

        return $score;
    }
}
