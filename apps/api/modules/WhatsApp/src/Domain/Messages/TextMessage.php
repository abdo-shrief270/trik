<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Domain\Messages;

use InvalidArgumentException;

final readonly class TextMessage implements OutgoingMessage
{
    public const MAX_LENGTH = 4096;

    public function __construct(public string $body, public bool $previewUrl = false)
    {
        if (trim($body) === '' || mb_strlen($body) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Text must be 1 to 4096 characters.');
        }
    }

    public function type(): string
    {
        return 'text';
    }
}
