<?php

declare(strict_types=1);

namespace Modules\Waitlist\Domain;

/** Where the seller sells today. */
enum Platform: string
{
    case WhatsAppOnly = 'whatsapp';
    case Shopify = 'shopify';
    case EasyOrders = 'easyorders';
    case Other = 'other';
}
