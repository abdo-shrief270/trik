<?php

declare(strict_types=1);

namespace Modules\Risk\Domain;

use InvalidArgumentException;

/**
 * Points per rule. Defaults below; a seller can change any of them (-50…50), e.g. weigh a first order less
 * if most of their customers are new. Caps keep one signal from dominating.
 */
final readonly class RiskWeights
{
    public const DEFAULTS = [
        'baseline' => 20,
        'refused_before' => 25,        // per earlier order refused at the door, up to 2 counted
        'cancelled_before' => 10,      // per earlier cancelled order, up to 2
        'unreachable_before' => 10,    // per earlier order where the customer never answered confirmation, up to 2
        'delivered_before' => -10,     // per earlier delivered order, up to 3
        'first_order' => 15,
        'high_value' => 15,            // at least twice the seller's usual COD amount (half at 1.5×)
        'address_incomplete' => 15,    // no governorate and nothing deliverable (a third with a governorate)
        'address_flagged' => 10,       // an agent flagged the address to fix
        'late_night' => 5,             // placed between midnight and 6 am
    ];

    /** @var array<string, int> */
    public array $points;

    /** @param array<string, int> $overrides */
    public function __construct(array $overrides = [])
    {
        foreach ($overrides as $rule => $points) {
            if (! array_key_exists($rule, self::DEFAULTS)) {
                throw new InvalidArgumentException("Unknown risk rule: {$rule}");
            }
            if ($points < -50 || $points > 50) {
                throw new InvalidArgumentException("Risk weight out of range: {$rule}");
            }
        }
        $this->points = [...self::DEFAULTS, ...$overrides];
    }

    public function of(string $rule): int
    {
        return $this->points[$rule];
    }
}
