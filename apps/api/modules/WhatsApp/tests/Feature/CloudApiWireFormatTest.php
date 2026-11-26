<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\WhatsApp\Domain\Messages\ButtonsMessage;
use Modules\WhatsApp\Domain\Messages\TextMessage;
use Modules\WhatsApp\Domain\PhoneNumber;
use Modules\WhatsApp\Tests\Fixtures\ProviderFixture;

it('authenticates 360dialog with the channel API key', function (): void {
    ProviderFixture::for('360dialog')->adapter()->send(PhoneNumber::fromString('01012345678'), new TextMessage('hi'));

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://waba-v2.360dialog.io/messages'
        && $r->hasHeader('D360-API-KEY', 'd360-key')
        && $r['to'] === '201012345678'
        && $r['messaging_product'] === 'whatsapp'
        && $r['text']['body'] === 'hi');
});

it('authenticates Meta with the bearer token on the number\'s path', function (): void {
    ProviderFixture::for('meta_cloud')->adapter()->send(PhoneNumber::fromString('+201012345678'), new TextMessage('hi'), 'wamid.CTX');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://graph.facebook.com/v24.0/PNID-1/messages'
        && $r->hasHeader('Authorization', 'Bearer meta-token')
        && $r['context']['message_id'] === 'wamid.CTX');
});

it('builds reply buttons in the Cloud API shape', function (): void {
    ProviderFixture::for('360dialog')->adapter()->send(
        PhoneNumber::fromString('01012345678'),
        new ButtonsMessage('أكد؟', ['confirm' => 'تأكيد', 'edit' => 'تعديل']),
    );

    Http::assertSent(fn (Request $r): bool => $r['type'] === 'interactive'
        && $r['interactive']['type'] === 'button'
        && $r['interactive']['action']['buttons'][1] === ['type' => 'reply', 'reply' => ['id' => 'edit', 'title' => 'تعديل']]);
});

it('registers the 360dialog webhook with the secret header', function (): void {
    ProviderFixture::for('360dialog')->adapter()->configureWebhook('https://api.trik.chat/webhooks/whatsapp/360dialog/x', 's3cret');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://waba-v2.360dialog.io/v1/configs/webhook'
        && $r['headers'] === ['X-Trik-Webhook-Secret' => 's3cret']);
});

it('downloads 360dialog media through the 360dialog host, not Meta\'s', function (): void {
    ProviderFixture::for('360dialog')->adapter()->downloadMedia('media-1');

    Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://waba-v2.360dialog.io/whatsapp_business/attachments/?mid=1')
        && $r->hasHeader('D360-API-KEY', 'd360-key'));
    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'lookaside.fbsbx.com'));
});
