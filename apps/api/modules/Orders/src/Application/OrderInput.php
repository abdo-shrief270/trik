<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

use DateTimeInterface;
use InvalidArgumentException;
use Modules\Addresses\Domain\Address;
use Modules\Orders\Domain\OrderSource;
use Modules\Orders\Domain\OrderStatus;

/**
 * An order as a source hands it over: the store connectors (#332, #117) and chat (#105) all build
 * this and call RecordOrder. Money in minor units (piastres).
 */
final readonly class OrderInput
{
    /** @param list<OrderItemInput> $items */
    public function __construct(
        public OrderSource $source,
        public ?string $externalId,
        public ?string $number,
        public string $customerPhone,
        public ?string $customerName,
        public Address $address,
        public array $items,
        public DateTimeInterface $placedAt,
        public int $shipping = 0,
        public int $discount = 0,
        public ?int $total = null,
        public string $paymentMethod = 'cod',
        public string $currency = 'EGP',
        public ?OrderStatus $status = null,
        public ?string $note = null,
        public ?string $courier = null,
        public ?string $trackingNumber = null,
        public ?string $trackingUrl = null,
    ) {
        if ($items === []) {
            throw new InvalidArgumentException('An order needs at least one item.');
        }
        if (! in_array($paymentMethod, ['cod', 'prepaid'], true)) {
            throw new InvalidArgumentException('Payment method must be cod or prepaid.');
        }
        if ($this->subtotal() + $shipping - $discount < 0) {
            throw new InvalidArgumentException('The discount is larger than the order.');
        }
    }

    public function subtotal(): int
    {
        return array_sum(array_map(fn (OrderItemInput $i): int => $i->total(), $this->items));
    }

    /** The store's total when it sends one (it may include taxes we don't model), else computed. */
    public function grandTotal(): int
    {
        return $this->total ?? max(0, $this->subtotal() + $this->shipping - $this->discount);
    }

    public function codAmount(): int
    {
        return $this->paymentMethod === 'cod' ? $this->grandTotal() : 0;
    }
}
