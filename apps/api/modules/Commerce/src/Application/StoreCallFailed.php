<?php

declare(strict_types=1);

namespace Modules\Commerce\Application;

use RuntimeException;

/**
 * A call to a store platform that didn't work. Jobs read it to decide what to do (StoreHealth):
 *  - retryAfter set:  throttled or the platform is unavailable; release the job for that many seconds;
 *  - unauthorized:    the key or token was revoked; the store is marked uninstalled;
 *  - otherwise:       a permanent error (bad request, missing permission), reported and not retried.
 */
class StoreCallFailed extends RuntimeException
{
    // final: throttled() builds the subclass with `new static`
    final public function __construct(
        string $message,
        public readonly ?int $retryAfter = null,
        public readonly bool $unauthorized = false,
    ) {
        parent::__construct($message);
    }

    public static function throttled(int $seconds): static
    {
        return new static("Store API limit reached; retry in {$seconds}s.", max(1, $seconds));
    }
}
