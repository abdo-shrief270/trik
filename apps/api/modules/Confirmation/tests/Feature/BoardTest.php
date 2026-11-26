<?php

declare(strict_types=1);

use App\Support\Realtime\Realtime;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Addresses\Domain\Address;
use Modules\Addresses\Domain\Governorate;
use Modules\Confirmation\Application\ConfirmationEngine;
use Modules\Confirmation\Infrastructure\Models\ConfirmationRun;
use Modules\Orders\Application\OrderInput;
use Modules\Orders\Application\OrderItemInput;
use Modules\Orders\Application\RecordOrder;
use Modules\Orders\Domain\BoardStage;
use Modules\Orders\Domain\OrderSource;
use Modules\Orders\Domain\OrderStatus;
use Modules\Orders\Infrastructure\Models\Order;
use Modules\WhatsApp\Application\ConnectNumber;
use Modules\WhatsApp\Application\Jobs\SendOutgoingMessage;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Infrastructure\Models\Message;
use Modules\Workspaces\Domain\WorkspaceRole;

function boardOrder(string $id, int $price = 50000, ?Governorate $gov = null, string $phone = '01012345678'): OrderInput
{
    return new OrderInput(OrderSource::Shopify, $id, '#'.$id, $phone, 'Mona',
        new Address(raw: 'x', governorate: $gov, area: $gov ? 'a' : null, street: $gov ? 's' : null), [new OrderItemInput('x', 1, $price)], now());
}

function board(array $headers, string $query = ''): array
{
    return inboxAs($headers)->getJson('/v1/orders/board'.$query)->assertOk()->json();
}

beforeEach(function (): void {
    Queue::fake([SendOutgoingMessage::class]);
    app()->instance(Realtime::class, new RecordingRealtime);
    $this->owner = ownerWithWorkspace('Mona Store');
    $this->ws = $this->owner['workspace'];
    app(TenantContext::class)->run($this->ws, fn () => app(ConnectNumber::class)->handle(Provider::Fake, 'PNID-1', ['api_key' => 'k']));
});

it('sorts orders into the five columns with their counts', function (): void {
    inboxAs($this->owner['headers'])->putJson('/v1/confirmation/settings', ['enabled' => true, 'template_name' => 'order_confirmation', 'max_reminders' => 0, 'escalate_after_minutes' => 30])->assertOk();
    $record = app(RecordOrder::class);

    // awaiting customer: the template went out
    $awaiting = inWs($this->ws, fn () => $record->handle(boardOrder('A1')));
    // needs a call: no reply after the escalation delay
    $call = inWs($this->ws, fn () => $record->handle(boardOrder('A2')));
    Carbon::setTestNow(now()->addMinutes(31));
    inWs($this->ws, fn () => app(ConfirmationEngine::class)->advance(ConfirmationRun::query()->where('order_id', $call->id)->value('id')));
    Carbon::setTestNow();
    // confirmed, cancelled, and a prepaid order that stays new
    $confirmed = inWs($this->ws, fn () => $record->handle(boardOrder('A3')));
    inboxAs($this->owner['headers'])->postJson("/v1/orders/{$confirmed->id}/confirm")->assertOk();
    $cancelled = inWs($this->ws, fn () => $record->handle(boardOrder('A4')));
    inboxAs($this->owner['headers'])->postJson("/v1/orders/{$cancelled->id}/cancel")->assertOk();
    inWs($this->ws, fn () => $record->handle(new OrderInput(OrderSource::Shopify, 'P1', '#P1', '01011111111', null, new Address(raw: 'x'), [new OrderItemInput('x', 1, 100)], now()->subHour(), paymentMethod: 'prepaid')));

    $b = board($this->owner['headers']);
    expect($b['counts'])->toBe(['new' => 1, 'awaiting_customer' => 1, 'needs_call' => 1, 'confirmed' => 1, 'cancelled' => 1])
        ->and($b['stage'])->toBe('new')
        ->and($b['data'][0]['waiting_seconds'])->toBeGreaterThanOrEqual(3600);

    $calls = board($this->owner['headers'], '?stage=needs_call')['data'];
    expect($calls[0])->toMatchArray(['id' => $call->id, 'stage' => 'needs_call'])
        ->and($calls[0]['needs_call']['reason'])->toBe('no_reply');
    expect(board($this->owner['headers'], '?stage=awaiting_customer')['data'][0]['id'])->toBe($awaiting->id);
});

it('filters by owner, governorate, value and risk, and the counts follow the filters', function (): void {
    $agent = teammate($this->ws, WorkspaceRole::Agent);
    $record = app(RecordOrder::class);
    $cairo = inWs($this->ws, fn () => $record->handle(boardOrder('B1', 120000, Governorate::Cairo)));
    inWs($this->ws, fn () => $record->handle(boardOrder('B2', 30000, Governorate::Giza)));
    inWs($this->ws, fn () => $record->handle(boardOrder('B3', 80000)));
    inWs($this->ws, fn () => DB::table('orders_orders')->where('id', $cairo->id)->update(['risk_score' => 82]));

    inboxAs($this->owner['headers'])->patchJson("/v1/orders/{$cairo->id}", ['owner_id' => $agent['user']->id])->assertOk()->assertJsonPath('data.owner_id', $agent['user']->id);
    inboxAs($this->owner['headers'])->patchJson("/v1/orders/{$cairo->id}", ['owner_id' => ownerWithWorkspace('x')['user']->id])->assertUnprocessable()->assertJsonPath('code', 'owner-cannot-view');

    expect(board($agent['headers'], '?owner=me')['counts']['new'])->toBe(1)
        ->and(board($this->owner['headers'], '?owner=unassigned')['counts']['new'])->toBe(2)
        ->and(board($this->owner['headers'], '?governorate=C')['data'][0]['id'])->toBe($cairo->id)
        ->and(board($this->owner['headers'], '?min_total=50000')['counts']['new'])->toBe(2)
        ->and(board($this->owner['headers'], '?min_total=50000&max_total=100000')['counts']['new'])->toBe(1)
        ->and(board($this->owner['headers'], '?min_risk=80')['counts']['new'])->toBe(1);

    inboxAs($this->owner['headers'])->getJson('/v1/orders/board?governorate=XX')->assertUnprocessable();
    inboxAs($this->owner['headers'])->getJson('/v1/orders/board?owner=nobody')->assertUnprocessable();
});

it('confirms, cancels and reminds in bulk, reports each order, and writes the audit log', function (): void {
    inboxAs($this->owner['headers'])->putJson('/v1/confirmation/settings', ['enabled' => true, 'template_name' => 'order_confirmation'])->assertOk();
    $record = app(RecordOrder::class);
    [$a, $b, $c] = array_map(fn (string $id) => inWs($this->ws, fn () => $record->handle(boardOrder($id))), ['C1', 'C2', 'C3']);
    inboxAs($this->owner['headers'])->postJson("/v1/orders/{$c->id}/cancel")->assertOk();
    $foreign = inWs(ownerWithWorkspace('other')['workspace'], fn () => $record->handle(boardOrder('F1')));

    $res = inboxAs([...$this->owner['headers'], 'Idempotency-Key' => 'bulk-1'])->postJson('/v1/confirmation/bulk', [
        'action' => 'confirm', 'order_ids' => [$a->id, $c->id, $foreign->id],
    ])->assertOk()->json('data');
    expect($res)->toBe([
        ['order_id' => $a->id, 'result' => 'ok'],
        ['order_id' => $c->id, 'result' => 'error', 'code' => 'invalid-transition'],
        ['order_id' => $foreign->id, 'result' => 'not_found'], // another workspace's order is not touched
    ]);
    inWs($this->ws, fn () => expect(Order::query()->find($a->id)->status)->toBe(OrderStatus::Confirmed));

    $before = inWs($this->ws, fn () => Message::query()->where('type', 'template')->count());
    inboxAs([...$this->owner['headers'], 'Idempotency-Key' => 'bulk-2'])->postJson('/v1/confirmation/bulk', ['action' => 'remind', 'order_ids' => [$b->id]])
        ->assertOk()->assertJsonPath('data.0.result', 'sent');
    inWs($this->ws, fn () => expect(Message::query()->where('type', 'template')->count())->toBe($before + 1)
        ->and(ConfirmationRun::query()->where('order_id', $b->id)->value('reminders_sent'))->toBe(1));

    $audit = inboxAs($this->owner['headers'])->getJson('/v1/workspace/audit')->json('data');
    expect(collect($audit)->pluck('action')->all())->toContain('orders.bulk_confirm', 'orders.bulk_remind')
        ->and(collect($audit)->firstWhere('action', 'orders.bulk_confirm')['data'])->toMatchArray(['requested' => 3, 'succeeded' => 1, 'order_ids' => [$a->id]]);

    $agentNoConfirm = teammate($this->ws, WorkspaceRole::Custom);
    inboxAs([...$agentNoConfirm['headers'], 'Idempotency-Key' => 'bulk-3'])->postJson('/v1/confirmation/bulk', ['action' => 'cancel', 'order_ids' => [$b->id]])->assertForbidden();
    inboxAs([...$this->owner['headers'], 'Idempotency-Key' => 'bulk-4'])->postJson('/v1/confirmation/bulk', ['action' => 'cancel', 'order_ids' => array_map(fn () => (string) Str::uuid(), range(1, 101))])->assertUnprocessable();
});

it('computes the same column in PHP and in PostgreSQL for every status', function (): void {
    $order = inWs($this->ws, fn () => app(RecordOrder::class)->handle(boardOrder('Z1')));

    foreach (OrderStatus::cases() as $status) {
        foreach ([false, true] as $needsCall) {
            $stage = inWs($this->ws, function () use ($order, $status, $needsCall): string {
                DB::table('orders_orders')->where('id', $order->id)->update(['status' => $status->value, 'needs_call_at' => $needsCall ? now() : null]);

                return (string) DB::table('orders_orders')->where('id', $order->id)->value('stage');
            });
            expect($stage)->toBe(BoardStage::of($status, $needsCall)->value, "{$status->value} / needs call: ".($needsCall ? 'yes' : 'no'));
        }
    }
});
