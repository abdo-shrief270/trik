<?php

declare(strict_types=1);

namespace Modules\Risk\Domain;

/**
 * A score 0–100 and why: every rule that moved it, with its points and the numbers behind it,
 * biggest effect first. The sum of the points (then clamped) is the score: nothing is hidden.
 */
final readonly class RiskScore
{
    public const MODEL = 'rules-v1';

    /** @param list<array{code: string, points: int, params: array<string, int|float>}> $reasons */
    public function __construct(public int $score, public array $reasons) {}

    /** @return list<array{code: string, points: int, params: array<string, int|float>}> */
    public function top(int $n = 3): array
    {
        return array_slice(array_values(array_filter($this->reasons, fn (array $r): bool => $r['code'] !== 'baseline')), 0, $n);
    }
}
