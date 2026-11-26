<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Domain;

final readonly class MediaFile
{
    public function __construct(public string $mimeType, public string $contents) {}
}
