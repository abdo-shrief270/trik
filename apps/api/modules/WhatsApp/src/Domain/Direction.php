<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Domain;

enum Direction: string
{
    case Inbound = 'in';
    case Outbound = 'out';
}
