<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Domain;

final readonly class SendResult
{
    public function __construct(public string $messageId) {}
}
