<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Domain;

final readonly class NumberInfo
{
    public function __construct(
        public string $displayPhoneNumber,
        public ?string $verifiedName = null,
        public ?string $qualityRating = null,
        public ?string $messagingLimit = null,
    ) {}
}
