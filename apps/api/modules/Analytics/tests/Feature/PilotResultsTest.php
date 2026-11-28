<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Addresses\Domain\Address;
use Modules\Orders\Application\OrderInput;
use Modules\Orders\Application\OrderItemInput;
use Modules\Orders\Application\RecordOrder;
use Modules\Orders\Domain\OrderSource;
use Modules\Orders\Domain\OrderStatus;
use Modules\WhatsApp\Application\Jobs\SendOutgoingMessage;
use Modules\Workspaces\Domain\WorkspaceRole;

/** A store order placed at $placed with the status the store reports. */
function pilotOrder(string $ws, string $id, Carbon $placed, ?OrderStatus $status, string $payment = 'cod'): string
{
    return inWs($ws, fn () => app(RecordOrder::class)->handle(new OrderInput(OrderSource::Shopify, $id, '#'.$id, '0101234'.str_pad((string) (crc32($id) % 10000), 4, '0'), 'C',
        new Address(raw: 'x'), [new OrderItemInput('x', 1, 50000)], $placed, status: $status, paymentMethod: $payment))->id);
}

/** @param array<string, int> $statuses status => how many */
function pilotOrders(string $ws, string $prefix, Carbon $placed, array $statuses): void
{
    $n = 0;
    foreach ($statuses as $status => $count) {
        for ($i = 0; $i < $count; $i++) {
            pilotOrder($ws, $prefix.'-'.$n++, $placed->copy()->addMinutes($n), $status === 'new' ? null : OrderStatus::from($status));
        }
    }
}

beforeEach(function (): void {
    Queue::fake([SendOutgoingMessage::class]);
    $this->owner = ownerWithWorkspace('Laila Fashion');
    $this->ws = $this->owner['workspace'];
    $this->live = now()->subDays(10)->startOfHour();
});

it("compares before and after Trik from the orders' own statuses", function (): void {
    pilotOrders($this->ws, 'B', $this->live->copy()->subDays(30), ['delivered' => 6, 'returned' => 3, 'cancelled' => 1]);
    pilotOrders($this->ws, 'A', $this->live->copy()->addDays(2), ['delivered' => 7, 'returned' => 1, 'cancelled' => 1, 'new' => 1]);
    pilotOrder($this->ws, 'PREPAID', $this->live->copy()->addDay(), OrderStatus::Returned, 'prepaid'); // not COD: not counted
    pilotOrders($this->ws, 'OLD', $this->live->copy()->subDays(120), ['returned' => 5]); // before the baseline window

    // Trik confirmed one of the after orders 90 minutes after it was placed
    inWs($this->ws, fn () => DB::table('orders_orders')->where('external_id', 'A-0')->update(['confirmed_at' => DB::raw("placed_at + interval '90 minutes'")]));

    $res = inboxAs($this->owner['headers'])->putJson('/v1/analytics/pilot', ['live_at' => $this->live->toIso8601String()])->assertOk();

    expect($res->json('data.live_at_source'))->toBe('set')
        ->and($res->json('data.baseline.counts'))->toMatchArray(['placed' => 10, 'confirmed' => 9, 'cancelled' => 1, 'delivered' => 6, 'refused' => 3, 'open' => 0, 'timed' => 0])
        ->and($res->json('data.baseline.rates'))->toBe(['confirmation' => 0.9, 'cancellation' => 0.1, 'refusal' => 0.3333])
        ->and($res->json('data.after.counts'))->toMatchArray(['placed' => 10, 'confirmed' => 8, 'cancelled' => 1, 'delivered' => 7, 'refused' => 1, 'open' => 1, 'timed' => 1])
        ->and($res->json('data.after.counts.median_confirm_minutes'))->toEqual(90)
        ->and($res->json('data.after.rates'))->toBe(['confirmation' => 0.8889, 'cancellation' => 0.1111, 'refusal' => 0.125])
        ->and($res->json('data.change'))->toBe(['confirmation' => -1.1, 'cancellation' => 1.1, 'refusal' => -20.8]);
});

it('counts days in Cairo time', function (): void {
    // 23:30 UTC is already the next day in Cairo (UTC+2)
    $late = Carbon::parse(now()->subDays(2)->format('Y-m-d').' 23:30:00', 'UTC');
    pilotOrder($this->ws, 'D-1', $late, OrderStatus::Delivered);

    $daily = inboxAs($this->owner['headers'])->getJson('/v1/analytics/pilot')->assertOk()->json('data.daily');

    expect(array_column($daily, 'date'))->toBe([$late->copy()->setTimezone('Africa/Cairo')->toDateString()])
        ->and($daily[0])->toMatchArray(['placed' => 1, 'delivered' => 1])
        ->and($daily[0]['rates']['refusal'])->toEqual(0);
});

it('starts at the first WhatsApp confirmation until a date is set', function (): void {
    $none = inboxAs($this->owner['headers'])->getJson('/v1/analytics/pilot')->assertOk();
    expect($none->json('data.live_at'))->toBeNull()->and($none->json('data.baseline'))->toBeNull();

    $order = pilotOrder($this->ws, 'R-1', now()->subDays(3), null);
    inWs($this->ws, fn () => DB::table('confirmation_runs')->insert([
        'id' => (string) Str::uuid7(), 'workspace_id' => $this->ws, 'order_id' => $order, 'status' => 'sent',
        'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
    ]));

    $first = inboxAs($this->owner['headers'])->getJson('/v1/analytics/pilot')->assertOk();
    expect($first->json('data.live_at_source'))->toBe('first_confirmation')
        ->and(Carbon::parse($first->json('data.live_at'))->diffInMinutes(now()->subDays(3), true))->toBeLessThan(1);

    inboxAs($this->owner['headers'])->putJson('/v1/analytics/pilot', ['live_at' => now()->subDays(1)->toIso8601String(), 'baseline_days' => 30])
        ->assertOk()->assertJsonPath('data.live_at_source', 'set')->assertJsonPath('data.baseline_days', 30);
    inboxAs($this->owner['headers'])->putJson('/v1/analytics/pilot', ['live_at' => null])
        ->assertOk()->assertJsonPath('data.live_at_source', 'first_confirmation');
});

it('exports one row per week for the pilot review', function (): void {
    pilotOrders($this->ws, 'W', $this->live->copy()->subDays(20), ['delivered' => 2, 'returned' => 2]);
    pilotOrders($this->ws, 'X', $this->live->copy()->addDays(8), ['delivered' => 3]); // always a week after go-live's
    inboxAs($this->owner['headers'])->putJson('/v1/analytics/pilot', ['live_at' => $this->live->toIso8601String()])->assertOk();

    $csv = inboxAs($this->owner['headers'])->get('/v1/analytics/pilot/weekly.csv')->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8')->streamedContent();
    $rows = array_map('str_getcsv', array_filter(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))));

    expect($rows[0])->toBe(['week', 'period', 'orders', 'confirmed', 'cancelled', 'shipped', 'delivered', 'refused', 'open',
        'confirmation_rate', 'cancellation_rate', 'refusal_rate', 'median_minutes_to_confirm'])
        ->and(array_column(array_slice($rows, 1), 1))->toBe(['before', 'after'])
        ->and(array_slice($rows[1], 2, 6))->toBe(['4', '4', '0', '4', '2', '2'])
        ->and($rows[1][11])->toBe('0.5')
        ->and($rows[2][11])->toBe('0');
});

it("reads only the seller's orders, for owners and admins, with sane settings", function (): void {
    $other = ownerWithWorkspace('Other');
    pilotOrders($other['workspace'], 'O', now()->subDays(2), ['returned' => 4]);

    expect(inboxAs($this->owner['headers'])->getJson('/v1/analytics/pilot')->json('data.daily'))->toBe([]);

    $agent = teammate($this->ws, WorkspaceRole::Agent);
    inboxAs($agent['headers'])->getJson('/v1/analytics/pilot')->assertForbidden();
    inboxAs($agent['headers'])->get('/v1/analytics/pilot/weekly.csv')->assertForbidden();
    inboxAs($this->owner['headers'])->putJson('/v1/analytics/pilot', ['live_at' => now()->addDay()->toIso8601String()])->assertUnprocessable();
    inboxAs($this->owner['headers'])->putJson('/v1/analytics/pilot', ['baseline_days' => 5])->assertUnprocessable();
});
