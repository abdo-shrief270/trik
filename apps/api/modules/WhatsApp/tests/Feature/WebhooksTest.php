<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Modules\WhatsApp\Application\ConnectNumber;
use Modules\WhatsApp\Application\Events\MessageReceived;
use Modules\WhatsApp\Application\Events\MessageStatusChanged;
use Modules\WhatsApp\Application\SendMessage;
use Modules\WhatsApp\Domain\Messages\TextMessage;
use Modules\WhatsApp\Domain\PhoneNumber;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Infrastructure\Models\Message;
use Modules\WhatsApp\Infrastructure\Models\WebhookEvent;
use Modules\WhatsApp\Infrastructure\Models\WhatsAppNumber;
use Modules\WhatsApp\Infrastructure\Providers\FakeWhatsApp;
use Modules\WhatsApp\Tests\Fixtures\CloudApi;

/** @return array{workspace: string, number: WhatsAppNumber, headers: array<string, string>} */
function numberIn(string $workspaceName, string $provider = 'fake', string $externalId = 'PNID-1'): array
{
    $owner = ownerWithWorkspace($workspaceName);
    $number = app(TenantContext::class)->run($owner['workspace'], fn () => app(ConnectNumber::class)
        ->handle(Provider::from($provider), $externalId, ['api_key' => 'k', 'access_token' => 't'], 'WABA-1'));

    return ['workspace' => $owner['workspace'], 'number' => $number, 'headers' => $owner['headers']];
}

function inWorkspace(string $workspace, callable $fn): mixed
{
    return app(TenantContext::class)->run($workspace, $fn);
}

beforeEach(function (): void {
    config(['whatsapp.providers.meta_cloud.app_secret' => 'meta-app-secret', 'whatsapp.providers.meta_cloud.webhook_verify_token' => 'verify-me']);
});

it('stores, processes and announces an inbound message', function (): void {
    Event::fake([MessageReceived::class]);
    $n = numberIn('A');

    $this->withHeader('X-Trik-Webhook-Secret', $n['number']->webhookSecret())
        ->postJson("/webhooks/whatsapp/fake/{$n['number']->id}", CloudApi::inboundText())
        ->assertOk();

    inWorkspace($n['workspace'], function () use ($n): void {
        expect(WebhookEvent::query()->whereNotNull('processed_at')->count())->toBe(1);
        $m = Message::query()->sole();
        expect($m->direction->value)->toBe('in')
            ->and($m->contact_phone)->toBe('201012345678')
            ->and($m->status->value)->toBe('received')
            ->and($m->body['text'])->toBe('عايز أأكد الأوردر')
            ->and($m->number_id)->toBe($n['number']->id);
    });

    Event::assertDispatched(MessageReceived::class, fn (MessageReceived $e) => $e->workspaceId === $n['workspace'] && $e->message->contactName === 'Mona');
});

it('ignores a redelivered message', function (): void {
    Event::fake([MessageReceived::class]);
    $n = numberIn('A');

    foreach ([1, 2] as $_) {
        $this->withHeader('X-Trik-Webhook-Secret', $n['number']->webhookSecret())
            ->postJson("/webhooks/whatsapp/fake/{$n['number']->id}", CloudApi::inboundText())
            ->assertOk();
    }

    inWorkspace($n['workspace'], fn () => expect(Message::query()->count())->toBe(1));
    Event::assertDispatchedTimes(MessageReceived::class, 1);
});

it('rejects a webhook without the right secret', function (): void {
    $n = numberIn('A');

    $this->postJson("/webhooks/whatsapp/fake/{$n['number']->id}", CloudApi::inboundText())->assertUnauthorized();
    $this->withHeader('X-Trik-Webhook-Secret', 'wrong')->postJson("/webhooks/whatsapp/fake/{$n['number']->id}", CloudApi::inboundText())->assertUnauthorized();

    inWorkspace($n['workspace'], fn () => expect(WebhookEvent::query()->count())->toBe(0));
});

it('answers 404 for unknown numbers and the wrong provider path', function (): void {
    $n = numberIn('A');

    $this->postJson('/webhooks/whatsapp/fake/'.Str::uuid(), [])->assertNotFound();
    $this->postJson("/webhooks/whatsapp/360dialog/{$n['number']->id}", [])->assertNotFound();
});

it('moves outbound messages through sent, delivered and read, ignoring late statuses', function (): void {
    Event::fake([MessageStatusChanged::class]);
    $n = numberIn('A');
    $row = inWorkspace($n['workspace'], fn () => app(SendMessage::class)->handle($n['number'], PhoneNumber::fromString('01012345678'), new TextMessage('hi')));
    $wamid = app(FakeWhatsApp::class)->sent[0]['id'];

    foreach (['delivered', 'read', 'sent'] as $status) {
        $this->withHeader('X-Trik-Webhook-Secret', $n['number']->webhookSecret())
            ->postJson("/webhooks/whatsapp/fake/{$n['number']->id}", CloudApi::status($wamid, $status))
            ->assertOk();
    }

    $fresh = inWorkspace($n['workspace'], fn () => $row->fresh());
    expect($fresh->status->value)->toBe('read')
        ->and($fresh->delivered_at)->not->toBeNull()
        ->and($fresh->read_at)->not->toBeNull();

    Event::assertDispatchedTimes(MessageStatusChanged::class, 3); // sent (from the job), delivered, read
});

it('answers Meta\'s subscription handshake', function (): void {
    $this->get('/webhooks/whatsapp/meta_cloud?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345')
        ->assertOk()->assertSee('12345');
    $this->get('/webhooks/whatsapp/meta_cloud?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=1')->assertForbidden();
});

it('checks Meta\'s signature and routes each number to its own workspace', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['display_phone_number' => '+20 100 000 0000', 'verified_name' => 'Store'])]);
    $a = numberIn('A', 'meta_cloud', 'PNID-A');
    $b = numberIn('B', 'meta_cloud', 'PNID-B');

    $payload = CloudApi::inboundText('PNID-A', 'wamid.A1');
    $payload['entry'][] = CloudApi::inboundText('PNID-B', 'wamid.B1')['entry'][0];
    $payload['entry'][] = CloudApi::inboundText('PNID-UNKNOWN', 'wamid.X1')['entry'][0];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    $this->call('POST', '/webhooks/whatsapp/meta_cloud', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], $body)
        ->assertUnauthorized();

    $this->call('POST', '/webhooks/whatsapp/meta_cloud', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret'),
    ], $body)->assertOk();

    expect(inWorkspace($a['workspace'], fn () => Message::query()->pluck('provider_message_id')->all()))->toBe(['wamid.A1'])
        ->and(inWorkspace($b['workspace'], fn () => Message::query()->pluck('provider_message_id')->all()))->toBe(['wamid.B1']);
});
