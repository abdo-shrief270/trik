<?php

declare(strict_types=1);

namespace Modules\Commerce\Application\Shopify;

use RuntimeException;

/**
 * Why a store install (Shopify OAuth, EasyOrders callback) or a Shopify app launch was refused. The browser is sent back to the web app with this
 * code (?shopify_error=<code>); nothing more about the failure leaves the server.
 */
final class InstallFailed extends RuntimeException
{
    public const CODES = ['invalid-signature', 'invalid-shop', 'expired', 'shop-mismatch', 'forbidden', 'store-connected-elsewhere', 'denied', 'shopify-error', 'invalid-callback'];

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Shopify install refused: {$reason}");
    }
}
