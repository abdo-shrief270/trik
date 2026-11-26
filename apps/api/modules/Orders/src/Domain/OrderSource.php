<?php

declare(strict_types=1);

namespace Modules\Orders\Domain;

enum OrderSource: string
{
    case Chat = 'chat';
    case Shopify = 'shopify';
    case EasyOrders = 'easyorders';
}
