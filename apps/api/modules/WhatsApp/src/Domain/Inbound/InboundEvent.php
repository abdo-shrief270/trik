<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Domain\Inbound;

/** A normalised webhook event, the same whichever provider delivered it. */
interface InboundEvent
{
    /** The provider-side id of the business number the event belongs to. */
    public function phoneNumberId(): string;
}
