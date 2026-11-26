<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

use Modules\Orders\Domain\OrderStatus;

/** What other modules get to read of an order. */
final readonly class OrderSnapshot
{
    public function __construct(
        public string $id,
        public string $contactId,
        public string $number,
        public OrderStatus $status,
        public string $paymentMethod,
        public string $customerPhone,
        public ?string $customerName,
        public int $total,
        public string $currency,
        public bool $addressNeedsFix,
    ) {}

    public function isCod(): bool
    {
        return $this->paymentMethod === 'cod';
    }
}
