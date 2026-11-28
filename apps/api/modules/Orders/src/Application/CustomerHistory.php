<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

/** A customer's other orders with this seller (one workspace), as counts: what risk scoring reads. */
final readonly class CustomerHistory
{
    public function __construct(
        public int $orders = 0,
        public int $delivered = 0,
        public int $refused = 0,
        public int $cancelled = 0,
        public int $unreachable = 0,
    ) {}
}
