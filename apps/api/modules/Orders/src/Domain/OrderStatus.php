<?php

declare(strict_types=1);

namespace Modules\Orders\Domain;

/** The order lifecycle (packages/contracts Order.status). Moves forward only, as allowed below. */
enum OrderStatus: string
{
    case New = 'new';
    case Confirming = 'confirming';
    case AwaitingDeposit = 'awaiting_deposit';
    case Confirmed = 'confirmed';
    case ReadyToShip = 'ready_to_ship';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::New => [self::Confirming, self::AwaitingDeposit, self::Confirmed, self::ReadyToShip, self::Shipped, self::Cancelled],
            self::Confirming => [self::AwaitingDeposit, self::Confirmed, self::ReadyToShip, self::Shipped, self::Cancelled],
            self::AwaitingDeposit => [self::Confirmed, self::ReadyToShip, self::Shipped, self::Cancelled],
            self::Confirmed => [self::ReadyToShip, self::Shipped, self::Cancelled],
            self::ReadyToShip => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Delivered, self::Returned],
            self::Delivered => [self::Returned],
            self::Returned, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** Still waiting for the customer to say yes. */
    public function isPendingConfirmation(): bool
    {
        return in_array($this, [self::New, self::Confirming, self::AwaitingDeposit], true);
    }

    public function isFinal(): bool
    {
        return $this->next() === [];
    }

    /** The timestamp column a move to this status fills, if any. */
    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Confirmed => 'confirmed_at',
            self::Cancelled => 'cancelled_at',
            self::Shipped => 'shipped_at',
            self::Delivered => 'delivered_at',
            self::Returned => 'returned_at',
            default => null,
        };
    }
}
