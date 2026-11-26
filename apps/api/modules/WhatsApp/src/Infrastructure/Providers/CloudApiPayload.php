<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Infrastructure\Providers;

use InvalidArgumentException;
use Modules\WhatsApp\Domain\Messages\ButtonsMessage;
use Modules\WhatsApp\Domain\Messages\MediaMessage;
use Modules\WhatsApp\Domain\Messages\OutgoingMessage;
use Modules\WhatsApp\Domain\Messages\TemplateMessage;
use Modules\WhatsApp\Domain\Messages\TextMessage;
use Modules\WhatsApp\Domain\PhoneNumber;

/** Builds Cloud API message bodies. 360dialog's v2 API accepts the same shape. */
final class CloudApiPayload
{
    /** @return array<string, mixed> */
    public static function message(PhoneNumber $to, OutgoingMessage $message, ?string $replyTo = null): array
    {
        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to->digits,
            'type' => $message->type(),
            ...self::content($message),
        ];

        if ($replyTo !== null) {
            $body['context'] = ['message_id' => $replyTo];
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private static function content(OutgoingMessage $message): array
    {
        return match (true) {
            $message instanceof TextMessage => ['text' => ['body' => $message->body, 'preview_url' => $message->previewUrl]],
            $message instanceof TemplateMessage => ['template' => array_filter([
                'name' => $message->name,
                'language' => ['code' => $message->language],
                'components' => $message->components ?: null,
            ])],
            $message instanceof ButtonsMessage => ['interactive' => array_filter([
                'type' => 'button',
                'header' => $message->header !== null ? ['type' => 'text', 'text' => $message->header] : null,
                'body' => ['text' => $message->body],
                'footer' => $message->footer !== null ? ['text' => $message->footer] : null,
                'action' => ['buttons' => array_map(
                    fn (string $id, string $title): array => ['type' => 'reply', 'reply' => ['id' => $id, 'title' => $title]],
                    array_keys($message->buttons),
                    array_values($message->buttons),
                )],
            ])],
            $message instanceof MediaMessage => [$message->kind => array_filter([
                'link' => $message->link,
                'id' => $message->mediaId,
                'caption' => in_array($message->kind, ['audio', 'sticker'], true) ? null : $message->caption,
                'filename' => $message->kind === 'document' ? $message->filename : null,
            ], fn ($v) => $v !== null)],
            default => throw new InvalidArgumentException('Unsupported message: '.$message::class),
        };
    }
}
