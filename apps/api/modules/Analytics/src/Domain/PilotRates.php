<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain;

/**
 * The pilot's promise in three rates (fractions 0–1; null when there is nothing to measure yet):
 *  - confirmation: of the orders decided (confirmed or cancelled), how many were confirmed;
 *  - cancellation: of the orders decided, how many were cancelled;
 *  - refusal:      of the orders that reached the door (delivered or refused), how many were refused.
 * Orders still open, or still on the road, don't count yet: they would make every recent day look better.
 */
final readonly class PilotRates
{
    public function __construct(
        public ?float $confirmation,
        public ?float $cancellation,
        public ?float $refusal,
    ) {}

    /** @param array<string, mixed> $counts OrderStats counts (a period or a day); only confirmed, cancelled, delivered and refused are read */
    public static function from(array $counts): self
    {
        $decided = (int) ($counts['confirmed'] ?? 0) + (int) ($counts['cancelled'] ?? 0);
        $atDoor = (int) ($counts['delivered'] ?? 0) + (int) ($counts['refused'] ?? 0);

        return new self(
            $decided === 0 ? null : round((int) $counts['confirmed'] / $decided, 4),
            $decided === 0 ? null : round((int) $counts['cancelled'] / $decided, 4),
            $atDoor === 0 ? null : round((int) $counts['refused'] / $atDoor, 4),
        );
    }

    /**
     * After minus before, in percentage points (null when either side can't be measured).
     *
     * @return array{confirmation: float|null, cancellation: float|null, refusal: float|null}
     */
    public static function change(self $before, self $after): array
    {
        $diff = fn (?float $b, ?float $a): ?float => $a === null || $b === null ? null : round(($a - $b) * 100, 1);

        return [
            'confirmation' => $diff($before->confirmation, $after->confirmation),
            'cancellation' => $diff($before->cancellation, $after->cancellation),
            'refusal' => $diff($before->refusal, $after->refusal),
        ];
    }

    /** @return array{confirmation: float|null, cancellation: float|null, refusal: float|null} */
    public function toArray(): array
    {
        return ['confirmation' => $this->confirmation, 'cancellation' => $this->cancellation, 'refusal' => $this->refusal];
    }
}
