<?php

declare(strict_types=1);

namespace Modules\Orders\Domain;

/** The columns of the confirmation board, in order (the `stage` column computed by PostgreSQL). */
enum BoardStage: string
{
    case New = 'new';
    case AwaitingCustomer = 'awaiting_customer';
    case NeedsCall = 'needs_call';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    /** Mirrors the generated column (a test checks the two agree for every combination). */
    public static function of(OrderStatus $status, bool $needsCall): self
    {
        return match (true) {
            $status === OrderStatus::Cancelled => self::Cancelled,
            ! $status->isPendingConfirmation() => self::Confirmed,
            $needsCall => self::NeedsCall,
            $status === OrderStatus::New => self::New,
            default => self::AwaitingCustomer,
        };
    }
}
