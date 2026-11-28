<?php

declare(strict_types=1);

namespace Modules\Commerce\Domain;

/** A store platform Trik connects to. */
enum Platform: string
{
    case Shopify = 'shopify';
    case EasyOrders = 'easyorders';
}
