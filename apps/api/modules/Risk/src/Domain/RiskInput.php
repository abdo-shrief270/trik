<?php

declare(strict_types=1);

namespace Modules\Risk\Domain;

/**
 * What the score looks at, all from the seller's own data: the customer's history with this seller and
 * the order itself. Money in minor units; the hour in the seller's time zone (Cairo).
 */
final readonly class RiskInput
{
    public function __construct(
        public int $previousOrders,
        public int $delivered,
        public int $refused,
        public int $cancelled,
        public int $unreachable,
        public int $codAmount,
        public bool $prepaid,
        public ?int $usualCodAmount,
        public int $usualSample,
        public bool $governorateKnown,
        public bool $addressDeliverable,
        public bool $addressFlagged,
        public int $hour,
    ) {}

    /** @return array<string, int|bool|null> kept with the assessment, to explain it later */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
