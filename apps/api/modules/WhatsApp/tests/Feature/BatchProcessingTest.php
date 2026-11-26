<?php

declare(strict_types=1);

use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\WhatsApp\Application\ConnectNumber;
use Modules\WhatsApp\Application\Contracts\WebhookAdapter;
use Modules\WhatsApp\Application\Events\BroadcastProgressed;
use Modules\WhatsApp\Application\Events\MessageReceived;
use Modules\WhatsApp\Application\Events\MessageStatusChanged;
use Modules\WhatsApp\Application\Jobs\ProcessWebhookEvents;
use Modules\WhatsApp\Application\SendBroadcast;
use Modules\WhatsApp\Application\SendMessage;
use Modules\WhatsApp\Application\WebhookBatchProcessor;
use Modules\WhatsApp\Domain\Messages\TemplateMessage;
use Modules\WhatsApp\Domain\Messages\TextMessage;
use Modules\WhatsApp\Domain\PhoneNumber;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Infrastructure\Models\BroadcastSend;
use Modules\WhatsApp\Infrastructure\Models\Message;
use Modules\WhatsApp\Infrastructure\Models\WebhookEvent;
use Modules\WhatsApp\Infrastructure\Models\WhatsAppNumber;
use Modules\WhatsApp\Infrastructure\Providers\FakeWhatsApp;
use Modules\WhatsApp\Infrastructure\Webhooks\CloudApiPayloadParser;
use Modules\WhatsApp\Tests\Fixtures\CloudApi;

/** A fake-provider webhook adapter that chokes on one specific message id. */
final class PoisonAwareWebhook implements WebhookAdapter
{
    public function provider(): Provider
    {
        return Provider::Fake;
    }

    public function verify(array $headers, string $rawBody, ?string $numberSecret): bool
    {
        return true;
    }

    public function parse(array $payload): array
    {
        if (str_contains((string) json_encode($payload), 'wamid.POISON')) {
            throw new RuntimeException('cannot parse this one');
        }

        return (new CloudApiPayloadParser)->parse($payload);
    }
}

beforeEach(function (): void {
    $this->owner = ownerWithWorkspace();
    $this->ctx = app(TenantContext::class);
    $this->number = $this->ctx->run($this->owner['workspace'], fn (): WhatsAppNumber => app(ConnectNumber::class)->handle(Provider::Fake, 'PNID-1', ['api_key' => 'k']));
});

/** Stores deliveries without processing them, as if they arrived while the worker was busy. */
function storeDeliveries(string $workspace, string $numberId, array $payloads): void
{
    app(TenantContext::class)->run($workspace, function () use ($numberId, $payloads): void {
        foreach ($payloads as $payload) {
            WebhookEvent::query()->create(['number_id' => $numberId, 'provider' => Provider::Fake, 'payload' => $payload]);
        }
    });
}

it('processes a backlog of deliveries with a fixed number of queries', function (): void {
    Event::fake([MessageReceived::class]);
    $payloads = array_map(fn (int $i): array => CloudApi::inboundText(messageId: "wamid.IN{$i}", from: '2010'.str_pad((string) $i, 8, '0', STR_PAD_LEFT)), range(1, 120));
    storeDeliveries($this->owner['workspace'], $this->number->id, $payloads);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    (new ProcessWebhookEvents($this->owner['workspace']))->handle(app(WebhookBatchProcessor::class), app(EventPublisher::class), $this->ctx);

    expect($queries)->toBeLessThan(15); // not 120 × n
    $this->ctx->run($this->owner['workspace'], function (): void {
        expect(Message::query()->count())->toBe(120)
            ->and(WebhookEvent::query()->whereNull('processed_at')->count())->toBe(0);
    });
    Event::assertDispatchedTimes(MessageReceived::class, 120);
});

it('drops duplicates inside one batch too', function (): void {
    storeDeliveries($this->owner['workspace'], $this->number->id, [CloudApi::inboundText(), CloudApi::inboundText(), CloudApi::inboundText()]);

    ProcessWebhookEvents::dispatchSync($this->owner['workspace']);

    $this->ctx->run($this->owner['workspace'], fn () => expect(Message::query()->count())->toBe(1));
});

it('applies many statuses for many messages in one update, ending on the furthest one', function (): void {
    Event::fake([MessageStatusChanged::class]);
    [$ids, $wamids] = $this->ctx->run($this->owner['workspace'], function (): array {
        $ids = [];
        foreach (range(1, 3) as $_) {
            $ids[] = app(SendMessage::class)->handle($this->number, PhoneNumber::fromString('01012345678'), new TextMessage('hi'))->id;
        }

        return [$ids, array_column(app(FakeWhatsApp::class)->sent, 'id')];
    });

    storeDeliveries($this->owner['workspace'], $this->number->id, [
        CloudApi::status($wamids[0], 'read', timestamp: 1764150400),
        CloudApi::status($wamids[0], 'delivered', timestamp: 1764150350),
        CloudApi::status($wamids[1], 'delivered'),
        CloudApi::status($wamids[2], 'failed'),
        CloudApi::status($wamids[2], 'delivered'),
    ]);
    ProcessWebhookEvents::dispatchSync($this->owner['workspace']);

    $this->ctx->run($this->owner['workspace'], function () use ($ids): void {
        $rows = Message::query()->whereIn('id', $ids)->get()->keyBy('id');
        expect($rows[$ids[0]]->status->value)->toBe('read')
            ->and($rows[$ids[0]]->delivered_at?->timestamp)->toBe(1764150350)
            ->and($rows[$ids[1]]->status->value)->toBe('delivered')
            ->and($rows[$ids[2]]->status->value)->toBe('failed')
            ->and($rows[$ids[2]]->error['provider_errors'][0]['code'])->toBe(131026);
    });
});

it('isolates a delivery that keeps failing and processes the rest', function (): void {
    config(['whatsapp.adapters.fake.webhook' => PoisonAwareWebhook::class]);
    storeDeliveries($this->owner['workspace'], $this->number->id, [
        CloudApi::inboundText(messageId: 'wamid.OK1'),
        CloudApi::inboundText(messageId: 'wamid.POISON'),
        CloudApi::inboundText(messageId: 'wamid.OK2'),
    ]);

    ProcessWebhookEvents::dispatchSync($this->owner['workspace']);

    $this->ctx->run($this->owner['workspace'], function (): void {
        expect(Message::query()->pluck('provider_message_id')->sort()->values()->all())->toBe(['wamid.OK1', 'wamid.OK2']);
        $poison = WebhookEvent::query()->whereNull('processed_at')->sole();
        expect($poison->attempts)->toBe(1)->and($poison->last_error)->toBe('cannot parse this one');
    });
});

it('parks a delivery after too many failed attempts', function (): void {
    config(['whatsapp.adapters.fake.webhook' => PoisonAwareWebhook::class]);
    storeDeliveries($this->owner['workspace'], $this->number->id, [CloudApi::inboundText(messageId: 'wamid.POISON')]);

    foreach (range(1, ProcessWebhookEvents::MAX_ATTEMPTS + 2) as $_) {
        ProcessWebhookEvents::dispatchSync($this->owner['workspace']);
    }

    $this->ctx->run($this->owner['workspace'], fn () => expect(WebhookEvent::query()->sole()->attempts)->toBe(ProcessWebhookEvents::MAX_ATTEMPTS));
});

it('sends a broadcast in bulk and reports progress per broadcast, not per message', function (): void {
    Event::fake([BroadcastProgressed::class, MessageStatusChanged::class]);
    $broadcast = (string) str()->uuid();
    $recipients = array_map(fn (int $i) => PhoneNumber::fromString('0101234560'.$i), range(1, 5));

    $queued = $this->ctx->run($this->owner['workspace'], fn () => app(SendBroadcast::class)->handle(
        $this->number,
        $broadcast,
        new TemplateMessage('summer_sale', 'ar'),
        [...$recipients, $recipients[0]], // duplicate dropped
    ));

    expect($queued)->toBe(5)->and(app(FakeWhatsApp::class)->sent)->toHaveCount(5);

    $wamids = array_column(app(FakeWhatsApp::class)->sent, 'id');
    storeDeliveries($this->owner['workspace'], $this->number->id, array_map(fn (string $w) => CloudApi::status($w, 'delivered'), $wamids));
    ProcessWebhookEvents::dispatchSync($this->owner['workspace']);

    $this->ctx->run($this->owner['workspace'], function () use ($broadcast): void {
        expect(BroadcastSend::query()->where('broadcast_id', $broadcast)->where('status', 'delivered')->count())->toBe(5)
            ->and(Message::query()->count())->toBe(0); // campaign sends never enter the conversation table
    });

    Event::assertDispatchedTimes(BroadcastProgressed::class, 1);
    Event::assertDispatched(BroadcastProgressed::class, fn (BroadcastProgressed $e) => $e->broadcastId === $broadcast && $e->changes === ['delivered' => 5]);
    Event::assertNotDispatched(MessageStatusChanged::class);
});

it('keeps a broadcast within the number\'s messages-per-second limit', function (): void {
    config(['whatsapp.default_messages_per_second' => 2]);
    $broadcast = (string) str()->uuid();

    $this->ctx->run($this->owner['workspace'], fn () => app(SendBroadcast::class)->handle(
        $this->number,
        $broadcast,
        new TemplateMessage('summer_sale', 'ar'),
        array_map(fn (int $i) => PhoneNumber::fromString('0101234560'.$i), range(1, 5)),
    ));

    // the sync queue does not re-run released jobs: what is left waits for the next second
    expect(app(FakeWhatsApp::class)->sent)->toHaveCount(2);
    $this->ctx->run($this->owner['workspace'], fn () => expect(BroadcastSend::query()->where('status', 'queued')->count())->toBe(3));
});

it('runs daily maintenance', function (): void {
    $this->artisan('whatsapp:maintain')->assertSuccessful();
});
