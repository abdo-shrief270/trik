<?php

declare(strict_types=1);

namespace Modules\Waitlist\Application;

use Modules\Waitlist\Domain\MonthlyOrders;
use Modules\Waitlist\Domain\Platform;

/** A validated sign-up from the landing form. The email is already trimmed and lower-cased. */
final readonly class SignupInput
{
    public function __construct(
        public string $name,
        public string $storeName,
        public string $email,
        public string $phone,
        public Platform $platform,
        public MonthlyOrders $monthlyOrders,
        public string $locale,
        public ?string $ip,
    ) {}
}
