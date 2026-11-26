<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Domain\Messages;

/**
 * An approved template. Required outside the 24-hour customer service window.
 * Components use the Cloud API shape, e.g. [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Mona']]]].
 */
final readonly class TemplateMessage implements OutgoingMessage
{
    /** @param list<array<string, mixed>> $components */
    public function __construct(public string $name, public string $language, public array $components = []) {}

    public function type(): string
    {
        return 'template';
    }
}
