<?php

declare(strict_types=1);

namespace Modules\Commerce\Application\Shopify;

use RuntimeException;

/**
 * A Shopify call that didn't work. Jobs read it to decide what to do:
 *  - retryAfter set:  throttled or Shopify unavailable; release the job for that many seconds;
 *  - unauthorized:    the token was revoked (app removed); the store is marked uninstalled;
 *  - otherwise:       a permanent error (bad query, missing scope), reported and not retried.
 */
final class ShopifyCallFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfter = null,
        public readonly bool $unauthorized = false,
    ) {
        parent::__construct($message);
    }

    public static function throttled(int $seconds): self
    {
        return new self("Shopify API limit reached; retry in {$seconds}s.", max(1, $seconds));
    }
}
