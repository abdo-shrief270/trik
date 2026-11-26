<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Domain\Messages;

use InvalidArgumentException;

/** Up to three reply buttons, e.g. Confirm / Change / Cancel on an order card. */
final readonly class ButtonsMessage implements OutgoingMessage
{
    /** @param array<string, string> $buttons reply id => title (max 20 characters) */
    public function __construct(
        public string $body,
        public array $buttons,
        public ?string $header = null,
        public ?string $footer = null,
    ) {
        if ($buttons === [] || count($buttons) > 3) {
            throw new InvalidArgumentException('A buttons message needs 1 to 3 buttons.');
        }

        foreach ($buttons as $id => $title) {
            if ($id === '' || mb_strlen($title) < 1 || mb_strlen($title) > 20) {
                throw new InvalidArgumentException('Button ids must be set and titles 1 to 20 characters.');
            }
        }
    }

    public function type(): string
    {
        return 'interactive';
    }
}
