<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

use InvalidArgumentException;

final readonly class OrderItemInput
{
    public function __construct(
        public string $title,
        public int $quantity,
        public int $unitPrice,
        public ?string $sku = null,
        public ?string $variant = null,
        public ?string $externalId = null,
    ) {
        if ($quantity < 1 || $unitPrice < 0 || trim($title) === '') {
            throw new InvalidArgumentException('Each item needs a title, a quantity of at least 1 and a price.');
        }
    }

    public function total(): int
    {
        return $this->quantity * $this->unitPrice;
    }
}
