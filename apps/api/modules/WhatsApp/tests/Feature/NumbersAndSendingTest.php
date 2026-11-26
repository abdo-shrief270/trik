<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Modules\WhatsApp\Application\Events\MessageStatusChanged;
use Modules\WhatsApp\Application\Exceptions\ProviderRequestFailed;
use Modules\WhatsApp\Application\Jobs\SendOutgoingMessage;
use Modules\WhatsApp\Domain\Messages\TextMessage;
use Modules\WhatsApp\Domain\PhoneNumber;
use Modules\WhatsApp\Infrastructure\Models\Message;
use Modules\WhatsApp\Infrastructure\Providers\FakeWhatsApp;

function connectFake(array $headers, string $externalId = 'fake-1'): string
{
    return test()->withHeaders($headers)->postJson('/v1/whatsapp/numbers', [
        'provider' => 'fake', 'external_id' => $externalId, 'credentials' => ['api_key' => 'x'],
    ])->assertCreated()->json('data.id');
}

it('connects a 360dialog number and registers its webhook with a secret', function (): void {
    Http::fake(['waba-v2.360dialog.io/v1/configs/webhook' => Http::response(['ok' => true])]);
    $owner = ownerWithWorkspace();

    $response = $this->withHeaders($owner['headers'])->postJson('/v1/whatsapp/numbers', [
        'provider' => '360dialog',
        'external_id' => 'channel-abc',
        'display_phone_number' => '+20 100 000 0000',
        'credentials' => ['api_key' => 'd360-key'],
    ])->assertCreated();

    $id = $response->json('data.id');
    expect($response->json('data.webhook_url'))->toEndWith("/webhooks/whatsapp/360dialog/{$id}")
        ->and($response->json('data.status'))->toBe('active')
        ->and($response->json('data'))->not->toHaveKey('credentials');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://waba-v2.360dialog.io/v1/configs/webhook'
        && $r['url'] === $response->json('data.webhook_url')
        && strlen($r['headers']['X-Trik-Webhook-Secret']) === 48);

    $this->withHeaders($owner['headers'])->getJson('/v1/whatsapp/numbers')
        ->assertOk()
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.capabilities', ['templates', 'media', 'interactive_messages', 'mark_read', 'configure_webhook']);
});

it('stores credentials encrypted', function (): void {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $owner = ownerWithWorkspace();
    $this->withHeaders($owner['headers'])->postJson('/v1/whatsapp/numbers', [
        'provider' => '360dialog', 'external_id' => 'channel-enc', 'credentials' => ['api_key' => 'plain-secret-key'],
    ])->assertCreated();

    $raw = app(TenantContext::class)->run($owner['workspace'], fn () => DB::table('whatsapp_numbers')->value('credentials'));
    expect($raw)->not->toContain('plain-secret-key');
});

it('refuses a number that is already connected', function (): void {
    $owner = ownerWithWorkspace();
    connectFake($owner['headers'], 'dup');

    $this->withHeaders($owner['headers'])->postJson('/v1/whatsapp/numbers', [
        'provider' => 'fake', 'external_id' => 'dup', 'credentials' => ['api_key' => 'x'],
    ])->assertConflict();
});

it('queues, sends and logs a message', function (): void {
    Event::fake([MessageStatusChanged::class]);
    $owner = ownerWithWorkspace();
    $number = connectFake($owner['headers']);

    $response = $this->withHeaders($owner['headers'])->withHeader('Idempotency-Key', (string) str()->uuid())->postJson("/v1/whatsapp/numbers/{$number}/messages", [
        'to' => '01012345678', 'type' => 'text', 'text' => 'أوردرك اتشحن 🚚',
    ])->assertAccepted();

    $sent = app(FakeWhatsApp::class)->sent;
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['to']->digits)->toBe('201012345678')
        ->and($sent[0]['message'])->toBeInstanceOf(TextMessage::class);

    $this->withHeaders($owner['headers'])->getJson("/v1/whatsapp/numbers/{$number}/messages")
        ->assertJsonPath('data.0.id', $response->json('data.id'))
        ->assertJsonPath('data.0.status', 'sent')
        ->assertJsonPath('data.0.provider_message_id', $sent[0]['id']);

    Event::assertDispatched(MessageStatusChanged::class, fn ($e) => $e->status->value === 'sent');
});

it('validates the recipient', function (): void {
    $owner = ownerWithWorkspace();
    $number = connectFake($owner['headers']);

    $this->withHeaders($owner['headers'])->withHeader('Idempotency-Key', (string) str()->uuid())->postJson("/v1/whatsapp/numbers/{$number}/messages", [
        'to' => 'not-a-number', 'type' => 'text', 'text' => 'hi',
    ])->assertUnprocessable()->assertJsonValidationErrors('to');
});

it('keeps a message queued and retries when the provider is throttling', function (): void {
    $owner = ownerWithWorkspace();
    $number = connectFake($owner['headers']);

    app(TenantContext::class)->run($owner['workspace'], function () use ($number): void {
        $row = Message::query()->create([
            'number_id' => $number, 'direction' => 'out', 'contact_phone' => '201012345678',
            'type' => 'text', 'body' => [], 'status' => 'queued',
        ]);
        app(FakeWhatsApp::class)->failNext(new ProviderRequestFailed('slow down', true, 429, '130429'));
        $job = new SendOutgoingMessage($row->id, PhoneNumber::fromString('01012345678'), new TextMessage('hi'));

        expect(fn () => app()->call([$job, 'handle']))->toThrow(ProviderRequestFailed::class);
        expect($row->fresh()->status->value)->toBe('queued')
            ->and($row->fresh()->attempts)->toBe(1)
            ->and($row->fresh()->error['retryable'])->toBeTrue();

        // second attempt succeeds
        app()->call([$job, 'handle']);
        expect($row->fresh()->status->value)->toBe('sent');

        // and a re-delivered job never sends twice
        app()->call([$job, 'handle']);
        expect(app(FakeWhatsApp::class)->sent)->toHaveCount(1);
    });
});

it('marks a message failed on a permanent error', function (): void {
    $owner = ownerWithWorkspace();
    $number = connectFake($owner['headers']);
    app(FakeWhatsApp::class)->failNext(new ProviderRequestFailed('not on WhatsApp', false, 400, '131026'));

    $this->withHeaders($owner['headers'])->withHeader('Idempotency-Key', (string) str()->uuid())->postJson("/v1/whatsapp/numbers/{$number}/messages", [
        'to' => '01012345678', 'type' => 'text', 'text' => 'hi',
    ])->assertAccepted()->assertJsonPath('data.status', 'failed');
});

it('manages templates through the number\'s provider', function (): void {
    $owner = ownerWithWorkspace();
    $number = connectFake($owner['headers']);

    $this->withHeaders($owner['headers'])->postJson("/v1/whatsapp/numbers/{$number}/templates", [
        'name' => 'order_shipped', 'language' => 'ar', 'category' => 'utility',
        'components' => [['type' => 'BODY', 'text' => 'أوردرك {{1}} اتشحن']],
    ])->assertCreated();

    $this->withHeaders($owner['headers'])->getJson("/v1/whatsapp/numbers/{$number}/templates")
        ->assertOk()->assertJsonPath('data.0.name', 'order_shipped');

    $this->withHeaders($owner['headers'])->deleteJson("/v1/whatsapp/numbers/{$number}/templates/order_shipped")->assertNoContent();
});

it('never exposes or uses another workspace\'s number', function (): void {
    $a = ownerWithWorkspace('A');
    $b = ownerWithWorkspace('B');
    $numberA = connectFake($a['headers']);
    app('auth')->forgetGuards();

    $this->withHeaders($b['headers'])->getJson('/v1/whatsapp/numbers')->assertOk()->assertJsonCount(0, 'data');
    $this->withHeaders($b['headers'])->withHeader('Idempotency-Key', (string) str()->uuid())->postJson("/v1/whatsapp/numbers/{$numberA}/messages", [
        'to' => '01012345678', 'type' => 'text', 'text' => 'hi',
    ])->assertNotFound();

    expect(app(FakeWhatsApp::class)->sent)->toBe([]);
});
