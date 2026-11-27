<?php

declare(strict_types=1);

namespace Modules\Waitlist\Domain;

/** Orders a month, in the bands the landing form offers. */
enum MonthlyOrders: string
{
    case Under300 = 's';
    case UpTo1500 = 'm';
    case UpTo5000 = 'l';
    case Over5000 = 'xl';

    public function label(): string
    {
        return match ($this) {
            self::Under300 => '< 300',
            self::UpTo1500 => '300–1,500',
            self::UpTo5000 => '1,500–5,000',
            self::Over5000 => '> 5,000',
        };
    }
}
