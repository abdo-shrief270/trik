<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Domain\Inbound;

final readonly class InboundMessage implements InboundEvent
{
    /**
     * @param  string  $type  text, image, audio, video, document, sticker, location, interactive, button, reaction, contacts, unsupported…
     * @param  string|null  $replyId  the button / list reply id, or a template quick-reply payload
     * @param  array<string, mixed>  $raw  the provider's message object as received
     */
    public function __construct(
        public string $businessPhoneNumberId,
        public string $messageId,
        public string $from,
        public string $type,
        public int $timestamp,
        public ?string $contactName = null,
        public ?string $text = null,
        public ?string $mediaId = null,
        public ?string $mimeType = null,
        public ?string $replyId = null,
        public ?string $contextMessageId = null,
        public array $raw = [],
    ) {}

    public function phoneNumberId(): string
    {
        return $this->businessPhoneNumberId;
    }
}
