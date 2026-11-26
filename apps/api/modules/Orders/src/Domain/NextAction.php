<?php

declare(strict_types=1);

namespace Modules\Orders\Domain;

use Modules\Addresses\Domain\Address;

/** What the team should do next with an order, shown on the order bar in chat (#105). */
enum NextAction: string
{
    case FixAddress = 'fix_address';
    case Confirm = 'confirm';
    case ResendDepositLink = 'resend_deposit_link';

    public static function for(OrderStatus $status, Address $address, bool $needsFix): ?self
    {
        if (! $status->isPendingConfirmation()) {
            return null;
        }

        // flagged by an agent, or nothing to deliver to at all (store orders carry a raw address,
        // which couriers accept: the structured form is required only once someone flags it)
        if ($needsFix || ($address->raw === null && ! $address->isDeliverable())) {
            return self::FixAddress;
        }

        return $status === OrderStatus::AwaitingDeposit ? self::ResendDepositLink : self::Confirm;
    }
}
