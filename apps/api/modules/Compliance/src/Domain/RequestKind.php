<?php

declare(strict_types=1);

namespace Modules\Compliance\Domain;

enum RequestKind: string
{
    /** Gather what Trik holds about a customer, for the store owner (Shopify: within 30 days). */
    case DataRequest = 'data_request';

    /** Erase a customer's personal data. */
    case CustomerRedact = 'customer_redact';

    /** Erase all customer data a store sent (Shopify: 48 hours after an uninstall). */
    case ShopRedact = 'shop_redact';

    public function isRedaction(): bool
    {
        return $this !== self::DataRequest;
    }
}
