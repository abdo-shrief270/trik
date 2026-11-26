<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Application;

use Modules\WhatsApp\Domain\Messages\ButtonsMessage;
use Modules\WhatsApp\Domain\Messages\MediaMessage;
use Modules\WhatsApp\Domain\Messages\OutgoingMessage;
use Modules\WhatsApp\Domain\Messages\TemplateMessage;
use Modules\WhatsApp\Domain\Messages\TextMessage;

/** The request shape of an outgoing message, shared by every endpoint that sends one. */
final class OutgoingMessages
{
    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'type' => ['required', 'in:text,template,buttons,media'],
            'reply_to' => ['nullable', 'string', 'max:128'],
            'text' => ['required_if:type,text', 'string', 'max:4096'],
            'template' => ['required_if:type,template', 'array'],
            'template.name' => ['required_if:type,template', 'string', 'max:512'],
            'template.language' => ['required_if:type,template', 'string', 'max:16'],
            'template.components' => ['sometimes', 'array'],
            'buttons' => ['required_if:type,buttons', 'array'],
            'body' => ['required_if:type,buttons', 'string', 'max:1024'],
            'media' => ['required_if:type,media', 'array'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated with rules()
     *
     * @throws \InvalidArgumentException when the content is not valid for WhatsApp
     */
    public static function fromArray(array $data): OutgoingMessage
    {
        return match ($data['type']) {
            'text' => new TextMessage((string) $data['text']),
            'template' => new TemplateMessage(
                (string) data_get($data, 'template.name'),
                (string) data_get($data, 'template.language'),
                array_values((array) data_get($data, 'template.components', [])),
            ),
            'buttons' => new ButtonsMessage((string) $data['body'], array_map('strval', (array) $data['buttons'])),
            default => new MediaMessage(
                (string) data_get($data, 'media.kind'),
                data_get($data, 'media.link'),
                data_get($data, 'media.id'),
                data_get($data, 'media.caption'),
                data_get($data, 'media.filename'),
            ),
        };
    }

    /** A short text for conversation lists: the text, caption, button body or template name. */
    public static function preview(OutgoingMessage $message): ?string
    {
        return match (true) {
            $message instanceof TextMessage => $message->body,
            $message instanceof ButtonsMessage => $message->body,
            $message instanceof MediaMessage => $message->caption,
            $message instanceof TemplateMessage => $message->name,
            default => null,
        };
    }
}
