<?php

declare(strict_types=1);

use Modules\Commerce\Application\EasyOrders\EasyOrdersOrderMapper;
use Modules\Commerce\Application\Shopify\UnmappableOrder;
use Modules\Orders\Domain\OrderStatus;

function easyFixture(array $overrides = []): array
{
    return array_replace(json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/easyorders_order.json'), true), $overrides);
}

it('maps the documented order shape', function (): void {
    $m = (new EasyOrdersOrderMapper)->fromApi(easyFixture());
    $o = $m->input;

    expect($m->externalId)->toBe('2692e31f-27f6-472d-b4cd-c0c1c168511c')
        ->and($o->number)->toBe('#46')
        ->and($o->customerName)->toBe('منى عادل')
        ->and($o->customerPhone)->toBe('201012345678')
        ->and($o->subtotal())->toBe(115000)
        ->and([$o->shipping, $o->discount, $o->grandTotal(), $o->codAmount()])->toBe([5000, 10000, 110000, 110000])
        ->and($o->address->toArray())->toMatchArray(['raw' => '12 شارع التحرير، الدقي، الجيزة', 'governorate' => 'GZ'])
        ->and($o->items[0]->variant)->toBe('color: أزرق · size: M')
        ->and($o->items[1]->unitPrice)->toBe(25000)
        ->and($o->status)->toBeNull();
});

it('reads statuses and payment the way EasyOrders names them', function (string $status, ?OrderStatus $expected): void {
    expect((new EasyOrdersOrderMapper)->fromApi(easyFixture(['status' => $status]))->input->status)->toBe($expected);
})->with([
    ['pending', null], ['confirmed', OrderStatus::Confirmed], ['processing', OrderStatus::ReadyToShip], ['waiting_for_pickup', OrderStatus::ReadyToShip],
    ['in_delivery', OrderStatus::Shipped], ['delivered', OrderStatus::Delivered], ['canceled', OrderStatus::Cancelled],
    ['returning_from_delivery', OrderStatus::Returned], ['refunded', OrderStatus::Returned], ['pending_payment', null], ['paid', null], ['something-new', null],
]);

it('treats paid or card orders as prepaid', function (): void {
    expect((new EasyOrdersOrderMapper)->fromApi(easyFixture(['status' => 'paid']))->input->paymentMethod)->toBe('prepaid')
        ->and((new EasyOrdersOrderMapper)->fromApi(easyFixture(['payment_method' => 'card']))->input->codAmount())->toBe(0)
        ->and((new EasyOrdersOrderMapper)->fromApi(easyFixture(['payment_method' => null]))->input->paymentMethod)->toBe('cod');
});

it('refuses orders it cannot confirm, and falls back on a short number', function (): void {
    expect(fn () => (new EasyOrdersOrderMapper)->fromApi(easyFixture(['phone' => 'n/a'])))->toThrow(UnmappableOrder::class)
        ->and(fn () => (new EasyOrdersOrderMapper)->fromApi(easyFixture(['cart_items' => []])))->toThrow(UnmappableOrder::class)
        ->and((new EasyOrdersOrderMapper)->fromApi(easyFixture(['short_id' => null]))->input->number)->toBe('#2692E31F')
        ->and((new EasyOrdersOrderMapper)->fromApi(easyFixture(['government' => 'Nasr City']))->input->address->governorate)->toBeNull();
});
