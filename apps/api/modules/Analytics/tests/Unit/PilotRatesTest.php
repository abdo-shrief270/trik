<?php

declare(strict_types=1);

use Modules\Analytics\Domain\PilotRates;

it('measures confirmations among decided orders and refusals among orders that reached the door', function (): void {
    $r = PilotRates::from(['confirmed' => 9, 'cancelled' => 1, 'delivered' => 6, 'refused' => 3, 'open' => 5]);

    expect($r->toArray())->toBe(['confirmation' => 0.9, 'cancellation' => 0.1, 'refusal' => 0.3333]);
});

it('says nothing rather than zero when there is nothing to measure', function (): void {
    expect(PilotRates::from(['open' => 4])->toArray())->toBe(['confirmation' => null, 'cancellation' => null, 'refusal' => null])
        ->and(PilotRates::from(['confirmed' => 3, 'cancelled' => 0])->refusal)->toBeNull();
});

it('compares in percentage points, only where both sides are measured', function (): void {
    $before = new PilotRates(0.9, 0.1, 0.3333);
    $after = new PilotRates(0.8889, 0.1111, 0.125);

    expect(PilotRates::change($before, $after))->toBe(['confirmation' => -1.1, 'cancellation' => 1.1, 'refusal' => -20.8])
        ->and(PilotRates::change(new PilotRates(null, null, 0.2), $after))->toBe(['confirmation' => null, 'cancellation' => null, 'refusal' => -7.5]);
});
