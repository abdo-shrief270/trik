<?php

declare(strict_types=1);

namespace Modules\Risk\Domain;

/**
 * Refusal risk, rules-v1: a baseline plus explainable rules (RiskWeights), clamped to 0–100.
 * A prepaid order keeps 30% of the score: the money is in, so only a return is at stake.
 */
final class RiskScorer
{
    /** Below this many COD orders, "usual amount" means nothing yet; a fixed 3,000 EGP stands in. */
    public const MIN_SAMPLE = 20;

    public const FALLBACK_HIGH_VALUE = 300000;

    public function score(RiskInput $in, RiskWeights $w): RiskScore
    {
        $reasons = [];
        $add = function (string $code, int $points, array $params = []) use (&$reasons): void {
            if ($points !== 0) {
                $reasons[] = ['code' => $code, 'points' => $points, 'params' => $params];
            }
        };

        $add('baseline', $w->of('baseline'));

        if ($in->previousOrders === 0) {
            $add('first_order', $w->of('first_order'));
        }
        $add('refused_before', $w->of('refused_before') * min(2, $in->refused), ['count' => $in->refused]);
        $add('cancelled_before', $w->of('cancelled_before') * min(2, $in->cancelled), ['count' => $in->cancelled]);
        $add('unreachable_before', $w->of('unreachable_before') * min(2, $in->unreachable), ['count' => $in->unreachable]);
        $add('delivered_before', $w->of('delivered_before') * min(3, $in->delivered), ['count' => $in->delivered]);

        if (! $in->prepaid && $in->codAmount > 0) {
            if ($in->usualCodAmount !== null && $in->usualSample >= self::MIN_SAMPLE && $in->usualCodAmount > 0) {
                $ratio = $in->codAmount / $in->usualCodAmount;
                $points = $ratio >= 2 ? $w->of('high_value') : ($ratio >= 1.5 ? intdiv($w->of('high_value'), 2) : 0);
                $add('high_value', $points, ['ratio' => round($ratio, 1), 'amount' => $in->codAmount, 'usual' => $in->usualCodAmount]);
            } elseif ($in->codAmount >= self::FALLBACK_HIGH_VALUE) {
                $add('high_value', $w->of('high_value'), ['amount' => $in->codAmount, 'usual' => self::FALLBACK_HIGH_VALUE]);
            }
        }

        if (! $in->addressDeliverable) {
            $add('address_incomplete', $in->governorateKnown ? intdiv($w->of('address_incomplete'), 3) : $w->of('address_incomplete'));
        }
        if ($in->addressFlagged) {
            $add('address_flagged', $w->of('address_flagged'));
        }
        if ($in->hour >= 0 && $in->hour < 6) {
            $add('late_night', $w->of('late_night'), ['hour' => $in->hour]);
        }

        $sum = array_sum(array_column($reasons, 'points'));
        if ($in->prepaid && $sum > 0) {
            $add('prepaid', -(int) round($sum * 0.7));
            $sum = array_sum(array_column($reasons, 'points'));
        }

        usort($reasons, fn (array $a, array $b): int => abs($b['points']) <=> abs($a['points']));

        return new RiskScore(max(0, min(100, $sum)), $reasons);
    }
}
