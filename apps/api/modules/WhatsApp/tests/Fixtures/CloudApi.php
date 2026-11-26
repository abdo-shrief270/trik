<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Tests\Fixtures;

/** Sample Cloud API webhook payloads, as both Meta and 360dialog deliver them. */
final class CloudApi
{
    /** @return array<string, mixed> */
    public static function inboundText(string $phoneNumberId = 'PNID-1', string $messageId = 'wamid.IN1', string $from = '201012345678', string $text = 'عايز أأكد الأوردر'): array
    {
        return self::wrap($phoneNumberId, [
            'contacts' => [['profile' => ['name' => 'Mona'], 'wa_id' => $from]],
            'messages' => [[
                'from' => $from, 'id' => $messageId, 'timestamp' => '1764150000',
                'type' => 'text', 'text' => ['body' => $text],
            ]],
        ]);
    }

    /** @return array<string, mixed> */
    public static function buttonReply(string $phoneNumberId = 'PNID-1', string $replyId = 'confirm'): array
    {
        return self::wrap($phoneNumberId, [
            'contacts' => [['profile' => ['name' => 'Mona'], 'wa_id' => '201012345678']],
            'messages' => [[
                'from' => '201012345678', 'id' => 'wamid.BTN1', 'timestamp' => '1764150100',
                'context' => ['from' => '201000000000', 'id' => 'wamid.OUT1'],
                'type' => 'interactive',
                'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $replyId, 'title' => 'تأكيد']],
            ]],
        ]);
    }

    /** @return array<string, mixed> */
    public static function image(string $phoneNumberId = 'PNID-1'): array
    {
        return self::wrap($phoneNumberId, [
            'messages' => [[
                'from' => '201012345678', 'id' => 'wamid.IMG1', 'timestamp' => '1764150200',
                'type' => 'image', 'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg', 'caption' => 'العنوان'],
            ]],
        ]);
    }

    /** @return array<string, mixed> */
    public static function status(string $messageId, string $status, string $phoneNumberId = 'PNID-1', int $timestamp = 1764150300): array
    {
        return self::wrap($phoneNumberId, [
            'statuses' => [array_filter([
                'id' => $messageId, 'status' => $status, 'timestamp' => (string) $timestamp, 'recipient_id' => '201012345678',
                'pricing' => ['billable' => true, 'category' => 'utility'],
                'errors' => $status === 'failed' ? [['code' => 131026, 'title' => 'Message undeliverable']] : null,
            ])],
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public static function wrap(string $phoneNumberId, array $value): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '201000000000', 'phone_number_id' => $phoneNumberId],
                        ...$value,
                    ],
                ]],
            ]],
        ];
    }
}
