<?php

declare(strict_types=1);

namespace Modules\Commerce\Application\Shopify;

use DateTimeImmutable;
use Modules\Orders\Application\OrderInput;

/** A store order ready for RecordOrder, with the store's id and version (its updated_at). */
final readonly class MappedOrder
{
    public function __construct(
        public OrderInput $input,
        public string $externalId,
        public DateTimeImmutable $updatedAt,
    ) {}
}
