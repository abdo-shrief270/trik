<?php

declare(strict_types=1);

namespace Modules\Commerce\Application\Shopify;

use Modules\Commerce\Application\StoreCallFailed;

/** A Shopify Admin API call that didn't work (see StoreCallFailed for what jobs do with it). */
final class ShopifyCallFailed extends StoreCallFailed {}
