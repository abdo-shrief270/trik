<?php

declare(strict_types=1);

use Modules\Risk\Domain\RiskInput;
use Modules\Risk\Domain\RiskScorer;
use Modules\Risk\Domain\RiskWeights;

/** A plain order from a customer with no history, at noon, deliverable, COD 900 EGP against a usual 800. */
function riskInput(array $o = []): RiskInput
{
    $d = [
        'previousOrders' => 0, 'delivered' => 0, 'refused' => 0, 'cancelled' => 0, 'unreachable' => 0,
        'codAmount' => 90000, 'prepaid' => false, 'usualCodAmount' => 80000, 'usualSample' => 100,
        'governorateKnown' => true, 'addressDeliverable' => true, 'addressFlagged' => false, 'hour' => 12,
    ];

    return new RiskInput(...[...$d, ...$o]);
}

function scoreOf(array $o = [], array $weights = []): array
{
    $s = (new RiskScorer)->score(riskInput($o), new RiskWeights($weights));

    return [$s->score, array_column($s->reasons, 'points', 'code')];
}

it('scores a new customer above a known good one', function (): void {
    expect(scoreOf())->toBe([35, ['baseline' => 20, 'first_order' => 15]])
        ->and(scoreOf(['previousOrders' => 4, 'delivered' => 4])[0])->toBe(0); // 20 − 30 (three counted), floored
});

it('weighs refusals most, and caps every signal', function (): void {
    [$score, $points] = scoreOf(['previousOrders' => 5, 'refused' => 5, 'cancelled' => 1, 'unreachable' => 3]);

    expect($points)->toBe(['refused_before' => 50, 'baseline' => 20, 'unreachable_before' => 20, 'cancelled_before' => 10])
        ->and($score)->toBe(100);
});

it('flags unusually large COD orders against the seller\'s own usual amount', function (): void {
    expect(scoreOf(['codAmount' => 160000])[1]['high_value'] ?? 0)->toBe(15)   // 2×
        ->and(scoreOf(['codAmount' => 120000])[1]['high_value'] ?? 0)->toBe(7)   // 1.5×
        ->and(scoreOf(['codAmount' => 110000])[1])->not->toHaveKey('high_value')
        // too few orders to know what is usual: a fixed 3,000 EGP stands in
        ->and(scoreOf(['usualSample' => 5, 'codAmount' => 300000])[1]['high_value'] ?? 0)->toBe(15)
        ->and(scoreOf(['usualSample' => 5, 'codAmount' => 250000])[1])->not->toHaveKey('high_value');
});

it('reads the address, the hour and prepayment', function (): void {
    expect(scoreOf(['addressDeliverable' => false, 'governorateKnown' => false])[1]['address_incomplete'])->toBe(15)
        ->and(scoreOf(['addressDeliverable' => false, 'governorateKnown' => true])[1]['address_incomplete'])->toBe(5)
        ->and(scoreOf(['addressFlagged' => true])[1]['address_flagged'])->toBe(10)
        ->and(scoreOf(['hour' => 3])[1]['late_night'])->toBe(5)
        ->and(scoreOf(['hour' => 6])[1])->not->toHaveKey('late_night')
        ->and(scoreOf(['prepaid' => true, 'codAmount' => 0]))->toBe([10, ['prepaid' => -25, 'baseline' => 20, 'first_order' => 15]]); // 35 keeps 30%: −24.5 rounds to −25
});

it("uses the seller's weights, and refuses nonsense ones", function (): void {
    expect(scoreOf([], ['first_order' => 0])[0])->toBe(20)
        ->and(scoreOf(['previousOrders' => 1, 'refused' => 1], ['refused_before' => 40])[0])->toBe(60);

    expect(fn () => new RiskWeights(['made_up' => 5]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RiskWeights(['first_order' => 90]))->toThrow(InvalidArgumentException::class);
});

it('explains itself: reasons sum to the score, biggest first, baseline out of the top', function (): void {
    $s = (new RiskScorer)->score(riskInput(['previousOrders' => 2, 'refused' => 1, 'hour' => 2, 'codAmount' => 200000]), new RiskWeights);

    expect(array_sum(array_column($s->reasons, 'points')))->toBe($s->score)
        ->and(array_column($s->top(), 'code'))->toBe(['refused_before', 'high_value', 'late_night'])
        ->and($s->top()[1]['params'])->toBe(['ratio' => 2.5, 'amount' => 200000, 'usual' => 80000]);
});
