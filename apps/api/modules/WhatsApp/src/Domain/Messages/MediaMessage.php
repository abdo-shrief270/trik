<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Domain\Messages;

use InvalidArgumentException;

final readonly class MediaMessage implements OutgoingMessage
{
    public const KINDS = ['image', 'video', 'audio', 'document', 'sticker'];

    public function __construct(
        public string $kind,
        public ?string $link = null,
        public ?string $mediaId = null,
        public ?string $caption = null,
        public ?string $filename = null,
    ) {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown media kind: {$kind}");
        }

        if (($link === null) === ($mediaId === null)) {
            throw new InvalidArgumentException('Give either a link or a media id.');
        }
    }

    public function type(): string
    {
        return $this->kind;
    }
}
