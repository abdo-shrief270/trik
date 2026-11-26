<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application\Exceptions;

use Modules\WhatsApp\Domain\Capability;
use Modules\WhatsApp\Domain\Provider;
use RuntimeException;

final class UnsupportedCapability extends RuntimeException
{
    public static function for(Provider $provider, Capability $capability): self
    {
        return new self("{$provider->value} does not support {$capability->value}.");
    }
}
