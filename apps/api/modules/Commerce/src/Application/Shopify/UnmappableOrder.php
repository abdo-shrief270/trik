<?php

declare(strict_types=1);

namespace Modules\Commerce\Application\Shopify;

use RuntimeException;

/**
 * A store order Trik can't take (no phone, no items left). Not an error to retry: the delivery is
 * marked processed with this reason as its outcome, and the store's next update tries again.
 */
final class UnmappableOrder extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
