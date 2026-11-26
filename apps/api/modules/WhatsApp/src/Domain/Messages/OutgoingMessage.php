<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Domain\Messages;

/** Something Trik sends. Adapters turn it into their wire format. */
interface OutgoingMessage
{
    /** The WhatsApp message type: text, template, interactive, image, … */
    public function type(): string;
}
