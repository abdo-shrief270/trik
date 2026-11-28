<?php

declare(strict_types=1);

use App\Support\Realtime\Realtime;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Modules\Addresses\Domain\Address;
use Modules\Confirmation\Application\ConfirmationEngine;
use Modules\Confirmation\Infrastructure\Models\ConfirmationRun;
use Modules\Orders\Application\Events\OrderConfirmed;
use Modules\Orders\Application\OrderInput;
use Modules\Orders\Application\OrderItemInput;
use Modules\Orders\Application\RecordOrder;
use Modules\Orders\Domain\OrderSource;
use Modules\Orders\Infrastructure\Models\Order;
use Modules\WhatsApp\Application\ConnectNumber;
use Modules\WhatsApp\Application\Jobs\SendOutgoingMessage;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Infrastructure\Models\Message;
use Modules\WhatsApp\Infrastructure\Models\WhatsAppNumber;
use Modules\WhatsApp\Tests\Fixtures\CloudApi;
use Modules\Workspaces\Domain\WorkspaceRole;

function codOrder(string $externalId = 'S-1', string $payment = 'cod'): OrderInput
{
    return new OrderInput(OrderSource::Shopify, $externalId, '#'.$externalId, '01012345678', 'Mona', new Address(raw: '12 التحرير، الدقي'),
        [new OrderItemInput('فستان', 1, 90000)], now(), shipping: 5000, paymentMethod: $payment);
}

/** The customer presses a button on the template. */
function press(WhatsAppNumber $number, string $payload, string $from = '201012345678', string $id = 'wamid.BTN'): void
{
    deliver($number, CloudApi::wrap('PNID-1', [
        'messages' => [['from' => $from, 'id' => $id, 'timestamp' => (string) time(), 'type' => 'button', 'button' => ['text' => 'تأكيد', 'payload' => $payload]]],
    ]));
}

beforeEach(function (): void {
    Queue::fake([SendOutgoingMessage::class]); // the engine's own jobs run inline (sync queue)
    $this->realtime = new RecordingRealtime;
    app()->instance(Realtime::class, $this->realtime);
    $this->owner = ownerWithWorkspace('Mona Store');
    $this->ws = $this->owner['workspace'];
    $this->ctx = app(TenantContext::class);
    $this->number = $this->ctx->run($this->ws, fn (): WhatsAppNumber => app(ConnectNumber::class)->handle(Provider::Fake, 'PNID-1', ['api_key' => 'k']));

    inboxAs($this->owner['headers'])->putJson('/v1/confirmation/settings', [
        'enabled' => true, 'template_name' => 'order_confirmation', 'template_language' => 'ar',
        'reminder_after_minutes' => 120, 'max_reminders' => 1, 'escalate_after_minutes' => 60,
    ])->assertOk();
});

it('sends the confirmation template with the three buttons as soon as a COD order arrives', function (): void {
    $order = inWs($this->ws, fn () => app(RecordOrder::class)->handle(codOrder()));

    inWs($this->ws, function () use ($order): void {
        $run = ConfirmationRun::query()->sole();
        $msg = Message::query()->sole();
        $components = $msg->body['template']['components'];

        expect($run->status->value)->toBe('sent')
            ->and($run->messages_sent)->toBe(1)
            ->and($run->next_action_at->diffInMinutes(now(), true))->toBeGreaterThan(119)
            ->and($msg->body['template']['name'])->toBe('order_confirmation')
            ->and($msg->contact_phone)->toBe('201012345678')
            ->and(array_column($components[0]['parameters'], 'text'))->toBe(['Mona', '#S-1', '950 ج.م'])
            ->and(array_map(fn ($b) => $b['parameters'][0]['payload'], array_slice($components, 1)))->toBe([
                "trik:c1:confirm:{$run->id}", "trik:c1:change:{$run->id}", "trik:c1:cancel:{$run->id}",
            ])
            ->and(Order::query()->find($order->id)->status->value)->toBe('confirming');
    });
    Queue::assertPushed(SendOutgoingMessage::class);
});

it('never asks about an order placed long ago (a store backfill), only recent ones', function (): void {
    $old = new OrderInput(OrderSource::Shopify, 'OLD-1', '#OLD-1', '01012345678', 'Mona', new Address(raw: '12 التحرير، الدقي'),
        [new OrderItemInput('فستان', 1, 90000)], now()->subDays(20), shipping: 5000);
    $edge = new OrderInput(OrderSource::Shopify, 'EDGE-1', '#EDGE-1', '01012345679', 'Nour', new Address(raw: '12 التحرير، الدقي'),
        [new OrderItemInput('فستان', 1, 90000)], now()->subHours(47), shipping: 5000);

    inWs($this->ws, fn () => app(RecordOrder::class)->handle($old));
    inWs($this->ws, fn () => expect(ConfirmationRun::query()->count())->toBe(0)->and(Message::query()->count())->toBe(0));

    inWs($this->ws, fn () => app(RecordOrder::class)->handle($edge));
    inWs($this->ws, fn () => expect(ConfirmationRun::query()->count())->toBe(1));
    Queue::assertPushed(SendOutgoingMessage::class, 1);
});

it('confirms the order when the customer presses Confirm, once, and thanks them', function (): void {
    Event::fake([OrderConfirmed::class]);
    $order = inWs($this->ws, fn () => app(RecordOrder::class)->handle(codOrder()));
    $run = inWs($this->ws, fn () => ConfirmationRun::query()->sole());

    press($this->number, "trik:c1:confirm:{$run->id}", id: 'wamid.B1');
    press($this->number, "trik:c1:confirm:{$run->id}", id: 'wamid.B2'); // a second tap

    inWs($this->ws, function () use ($order, $run): void {
        expect(Order::query()->find($order->id)->status->value)->toBe('confirmed')
            ->and($run->refresh()->status->value)->toBe('resolved')
            ->and($run->outcome)->toBe('confirmed')
            ->and(Message::query()->where('direction', 'out')->where('type', 'text')->pluck('body')->map(fn ($b) => $b['text']['body'])->all())
            ->toBe(['تم تأكيد طلبك ✅ هنبلغك أول ما يتشحن.']);
        expect(DB::table('orders_events')->where('order_id', $order->id)->orderBy('created_at')->pluck('type')->all())
            ->toBe(['created', 'status_changed', 'confirmation_sent', 'confirmation_reply', 'status_changed']);
    });
    Event::assertDispatchedTimes(OrderConfirmed::class, 1);
    Event::assertDispatched(OrderConfirmed::class, fn (OrderConfirmed $e): bool => $e->channel === 'whatsapp');
});

it('cancels on Cancel, and hands Change to a person', function (): void {
    $a = inWs($this->ws, fn () => app(RecordOrder::class)->handle(codOrder('S-A')));
    $b = inWs($this->ws, fn () => app(RecordOrder::class)->handle(codOrder('S-B')));
    [$ra, $rb] = inWs($this->ws, fn () => [ConfirmationRun::query()->where('order_id', $a->id)->sole(), ConfirmationRun::query()->where('order_id', $b->id)->sole()]);

    press($this->number, "trik:c1:cancel:{$ra->id}", id: 'wamid.C1');
    press($this->number, "trik:c1:change:{$rb->id}", id: 'wamid.C2');

    inWs($this->ws, function () use ($a, $b, $ra, $rb): void {
        expect(Order::query()->find($a->id)->status->value)->toBe('cancelled')
            ->and($ra->refresh()->outcome)->toBe('cancelled')
            ->and(Order::query()->find($b->id)->status->value)->toBe('confirming') // waits for the agent
            ->and($rb->refresh()->status->value)->toBe('escalated')
            ->and($rb->outcome)->toBe('change_requested');
    });
    expect(collect($this->realtime->sent)->firstWhere('data.type', 'confirmation.escalated')['data'])
        ->toMatchArray(['order_id' => $b->id, 'reason' => 'change_requested']);
});

it('ignores a button pressed from another number', function (): void {
    $order = inWs($this->ws, fn () => app(RecordOrder::class)->handle(codOrder()));
    $run = inWs($this->ws, fn () => ConfirmationRun::query()->sole());

    press($this->number, "trik:c1:confirm:{$run->id}", from: '201099999999');

    inWs($this->ws, fn () => expect(Order::query()->find($order->id)->status->value)->toBe('confirming')->and($run->refresh()->status->value)->toBe('sent'));
});

it('reminds after the delay, then escalates to a person', function (): void {
    $order = inWs($this->ws, fn () => app(RecordOrder::class)->handle(codOrder()));
    $run = inWs($this->ws, fn () => ConfirmationRun::query()->sole());
    $engine = app(ConfirmationEngine::class);

    Carbon::setTestNow(now()->addMinutes(60));
    inWs($this->ws, fn () => $engine->advance($run->id)); // not due yet
    expect(inWs($this->ws, fn () => $run->refresh()->messages_sent))->toBe(1);

    Carbon::setTestNow(now()->addMinutes(61));
    inWs($this->ws, fn () => $engine->advance($run->id));
    inWs($this->ws, fn () => expect($run->refresh()->reminders_sent)->toBe(1)->and($run->messages_sent)->toBe(2)->and($run->status->value)->toBe('sent'));

    Carbon::setTestNow(now()->addMinutes(61));
    inWs($this->ws, fn () => $engine->advance($run->id));
    inWs($this->ws, function () use ($run, $order): void {
        expect($run->refresh()->status->value)->toBe('escalated')
            ->and($run->outcome)->toBe('no_reply')
            ->and(Message::query()->where('type', 'template')->count())->toBe(2)
            ->and(DB::table('orders_events')->where('order_id', $order->id)->pluck('type')->all())->toContain('confirmation_reminder', 'confirmation_escalated');
    });

    // a late Confirm still counts
    Carbon::setTestNow();
    press($this->number, "trik:c1:confirm:{$run->id}", id: 'wamid.LATE');
    inWs($this->ws, fn () => expect(Order::query()->find($order->id)->status->value)->toBe('confirmed'));
});

it('stops when the team settles the order first', function (): void {
    $order = inWs($this->ws, fn () => app(RecordOrder::class)->handle(codOrder()));
    inboxAs($this->owner['headers'])->postJson("/v1/orders/{$order->id}/confirm")->assertOk();

    $run = inWs($this->ws, fn () => ConfirmationRun::query()->sole());
    expect($run->status->value)->toBe('resolved')->and($run->outcome)->toBe('settled_elsewhere')->and($run->next_action_at)->toBeNull();

    inboxAs($this->owner['headers'])->getJson("/v1/orders/{$order->id}/confirmation")->assertOk()->assertJsonPath('data.outcome', 'settled_elsewhere');
});

it('escalates instead of confirming when the address is flagged', function (): void {
    $order = inWs($this->ws, fn () => app(RecordOrder::class)->handle(codOrder()));
    inboxAs($this->owner['headers'])->patchJson("/v1/orders/{$order->id}", ['address_needs_fix' => true])->assertOk();
    $run = inWs($this->ws, fn () => ConfirmationRun::query()->sole());

    press($this->number, "trik:c1:confirm:{$run->id}");

    inWs($this->ws, fn () => expect($run->refresh()->status->value)->toBe('escalated')->and($run->outcome)->toBe('address-needs-fix')
        ->and(Order::query()->find($order->id)->status->value)->toBe('confirming'));
});

it('leaves prepaid orders and workspaces without a template alone', function (): void {
    inWs($this->ws, fn () => app(RecordOrder::class)->handle(codOrder('P-1', 'prepaid')));
    expect(inWs($this->ws, fn () => ConfirmationRun::query()->count()))->toBe(0);

    $other = ownerWithWorkspace('No template');
    inWs($other['workspace'], fn () => app(ConnectNumber::class)->handle(Provider::Fake, 'PNID-9', ['api_key' => 'k']));
    inWs($other['workspace'], fn () => app(RecordOrder::class)->handle(codOrder('N-1')));
    expect(inWs($other['workspace'], fn () => ConfirmationRun::query()->count()))->toBe(0);

    inboxAs($other['headers'])->putJson('/v1/confirmation/settings', ['enabled' => true])->assertUnprocessable();
});

it('lets only managers change the settings, and keeps runs inside their workspace', function (): void {
    $agent = teammate($this->ws, WorkspaceRole::Agent);
    inboxAs($agent['headers'])->getJson('/v1/confirmation/settings')->assertOk()->assertJsonPath('data.template_name', 'order_confirmation');
    inboxAs($agent['headers'])->putJson('/v1/confirmation/settings', ['enabled' => false])->assertForbidden();
    inboxAs($this->owner['headers'])->putJson('/v1/confirmation/settings', ['template_name' => 'Bad Name!'])->assertUnprocessable();

    $order = inWs($this->ws, fn () => app(RecordOrder::class)->handle(codOrder()));
    $stranger = ownerWithWorkspace('Other');
    inboxAs($stranger['headers'])->getJson("/v1/orders/{$order->id}/confirmation")->assertNotFound();
    inboxAs($stranger['headers'])->putJson('/v1/confirmation/settings', ['number_id' => $this->number->id])->assertUnprocessable();
});

it('queues due runs from the minute scheduler', function (): void {
    $this->artisan('confirmation:tick')->assertSuccessful();
});
