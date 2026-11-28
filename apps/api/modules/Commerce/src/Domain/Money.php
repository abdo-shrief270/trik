<?php

declare(strict_types=1);

namespace Modules\Commerce\Domain;

use InvalidArgumentException;

/** Store amounts ("450.00", "1250.5", 99) to minor units (piastres), without float rounding errors. */
final class Money
{
    public static function minor(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }
        if (is_int($amount)) {
            return $amount * 100;
        }

        $s = is_float($amount) ? sprintf('%.6F', $amount) : trim($amount);

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $s, $m) !== 1) {
            throw new InvalidArgumentException("Not an amount: {$s}");
        }

        $frac = str_pad($m[3] ?? '', 3, '0');
        $minor = (int) $m[2] * 100 + (int) substr($frac, 0, 2);
        if ((int) $frac[2] >= 5) {
            $minor++; // half up on the third decimal
        }

        return $m[1] === '-' ? -$minor : $minor;
    }
}
