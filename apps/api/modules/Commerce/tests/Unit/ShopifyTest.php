<?php

declare(strict_types=1);

use Modules\Commerce\Application\Shopify\ShopifyOrderMapper;
use Modules\Commerce\Application\Shopify\UnmappableOrder;
use Modules\Commerce\Domain\Money;
use Modules\Commerce\Domain\Shopify\ShopDomain;
use Modules\Commerce\Domain\Shopify\ShopifySignature;
use Modules\Orders\Domain\OrderStatus;

function shopifyFixture(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/shopify_order.json'), true);
}

describe('ShopDomain', function (): void {
    it('accepts what sellers type and normalises it', function (string $input): void {
        expect(ShopDomain::fromUserInput($input)->value)->toBe('laila-fashion.myshopify.com');
    })->with(['laila-fashion', 'Laila-Fashion.myshopify.com', 'https://laila-fashion.myshopify.com/admin/orders', ' laila-fashion.myshopify.com ']);

    it('refuses anything that could send the client secret to another host', function (string $shop): void {
        expect(fn () => ShopDomain::fromShopify($shop))->toThrow(InvalidArgumentException::class);
    })->with([
        'laila.myshopify.com.evil.io', 'evil.io', 'laila.myshopify.com:8443', 'user@laila.myshopify.com', 'laila.myshopify.com/x',
        '-laila.myshopify.com', 'myshopify.com', '', 'laila.shopify.com', "laila.myshopify.com\n", 'a.b.myshopify.com',
    ]);

    it('also refuses a custom domain typed by a seller', function (): void {
        expect(fn () => ShopDomain::fromUserInput('shop.laila.com'))->toThrow(InvalidArgumentException::class)
            ->and(ShopDomain::fromUserInput('laila')->handle())->toBe('laila');
    });
});

describe('ShopifySignature', function (): void {
    it('verifies webhook bodies, with a rotated secret too', function (): void {
        $body = '{"id":1}';
        $sig = base64_encode(hash_hmac('sha256', $body, 'old-secret', true));

        expect(ShopifySignature::verifyWebhook($body, $sig, ['new-secret', 'old-secret']))->toBeTrue()
            ->and(ShopifySignature::verifyWebhook($body.' ', $sig, ['new-secret', 'old-secret']))->toBeFalse()
            ->and(ShopifySignature::verifyWebhook($body, '', ['old-secret']))->toBeFalse()
            ->and(ShopifySignature::verifyWebhook($body, $sig, ['']))->toBeFalse();
    });

    it('verifies OAuth query strings the way Shopify signs them', function (): void {
        // Shopify's documented example
        $query = ['code' => '0907a61c0c8d55e99db179b68161bc00', 'shop' => 'some-shop.myshopify.com', 'state' => '0.6784241404160823', 'timestamp' => '1337178173'];
        $query['hmac'] = hash_hmac('sha256', 'code=0907a61c0c8d55e99db179b68161bc00&shop=some-shop.myshopify.com&state=0.6784241404160823&timestamp=1337178173', 'hush');

        expect(ShopifySignature::verifyQuery($query, ['hush']))->toBeTrue()
            ->and(ShopifySignature::verifyQuery([...$query, 'shop' => 'evil.myshopify.com'], ['hush']))->toBeFalse()
            ->and(ShopifySignature::verifyQuery(array_diff_key($query, ['hmac' => 1]), ['hush']))->toBeFalse()
            ->and(ShopifySignature::queryMessage(['ids' => ['1', '2'], 'a' => 'x', 'hmac' => 'h']))->toBe('a=x&ids=["1", "2"]');
    });
});

describe('Money', function (): void {
    it('turns store amounts into piastres exactly', function (mixed $in, int $out): void {
        expect(Money::minor($in))->toBe($out);
    })->with([['450.00', 45000], ['1250.5', 125050], ['0.29', 29], ['19.995', 2000], ['7', 700], [12, 1200], ['-5.10', -510], [null, 0], ['', 0], [0.1, 10]]);

    it('refuses what is not an amount', function (): void {
        expect(fn () => Money::minor('1,250.00'))->toThrow(InvalidArgumentException::class);
    });
});

describe('ShopifyOrderMapper', function (): void {
    it('maps an Egyptian COD order from a webhook', function (): void {
        $m = (new ShopifyOrderMapper)->fromWebhook(shopifyFixture());
        $o = $m->input;

        expect($m->externalId)->toBe('5820117942336')
            ->and($m->updatedAt->format(DATE_ATOM))->toBe('2026-11-27T13:05:14+02:00')
            ->and($o->number)->toBe('#1048')
            ->and($o->customerPhone)->toBe('201012345678')
            ->and($o->customerName)->toBe('منى عادل')
            ->and($o->paymentMethod)->toBe('cod')
            ->and($o->status)->toBeNull()
            ->and(count($o->items))->toBe(2) // the bag was edited out (current_quantity 0)
            ->and($o->items[0]->unitPrice)->toBe(45000)
            ->and($o->items[0]->variant)->toBe('M / أزرق')
            ->and($o->items[1]->sku)->toBeNull()
            ->and($o->shipping)->toBe(6000)
            ->and($o->discount)->toBe(10000)
            ->and($o->grandTotal())->toBe(146000)
            ->and($o->codAmount())->toBe(146000)
            ->and($o->note)->toBe('اتصل قبل ما تيجي')
            ->and($o->address->toArray())->toBe([
                'raw' => '12 شارع التحرير، الدور التالت، شقة 7، الدقي، Giza', 'governorate' => 'GZ', 'area' => 'الدقي',
                'street' => '12 شارع التحرير', 'building' => null, 'floor' => null, 'apartment' => null, 'landmark' => null,
            ]);
    });

    it('reads status, payment and tracking', function (): void {
        $p = shopifyFixture();
        $paid = (new ShopifyOrderMapper)->fromWebhook([...$p, 'financial_status' => 'paid', 'fulfillment_status' => 'fulfilled',
            'fulfillments' => [['tracking_company' => 'Bosta', 'tracking_number' => null, 'tracking_numbers' => ['BST-77'], 'tracking_url' => 'https://bosta.co/t/BST-77']]]);
        $cancelled = (new ShopifyOrderMapper)->fromWebhook([...$p, 'cancelled_at' => '2026-11-27T14:00:00+02:00', 'fulfillment_status' => 'fulfilled']);

        expect($paid->input->paymentMethod)->toBe('prepaid')
            ->and($paid->input->codAmount())->toBe(0)
            ->and($paid->input->status)->toBe(OrderStatus::Shipped)
            ->and([$paid->input->courier, $paid->input->trackingNumber, $paid->input->trackingUrl])->toBe(['Bosta', 'BST-77', 'https://bosta.co/t/BST-77'])
            ->and($cancelled->input->status)->toBe(OrderStatus::Cancelled);
    });

    it('follows the courier to the door: delivered on the fulfillment means delivered', function (): void {
        $p = shopifyFixture();
        $rest = (new ShopifyOrderMapper)->fromWebhook([...$p, 'fulfillment_status' => 'fulfilled', 'fulfillments' => [['shipment_status' => 'in_transit'], ['shipment_status' => 'delivered', 'tracking_company' => 'Bosta']]]);
        $transit = (new ShopifyOrderMapper)->fromWebhook([...$p, 'fulfillment_status' => 'fulfilled', 'fulfillments' => [['shipment_status' => 'in_transit']]]);
        $cancelled = (new ShopifyOrderMapper)->fromWebhook([...$p, 'cancelled_at' => '2026-11-27T14:00:00+02:00', 'fulfillments' => [['shipment_status' => 'delivered']]]);
        $graphql = (new ShopifyOrderMapper)->fromGraphql([
            'legacyResourceId' => '1', 'name' => '#1', 'createdAt' => '2026-11-27T10:00:00Z', 'updatedAt' => '2026-11-27T10:00:00Z',
            'displayFulfillmentStatus' => 'FULFILLED', 'shippingAddress' => ['phone' => '01012345678'],
            'fulfillments' => [['displayStatus' => 'DELIVERED', 'trackingInfo' => []]],
        ], [['title' => 'x', 'quantity' => 1, 'originalUnitPriceSet' => ['shopMoney' => ['amount' => '10']]]]);

        expect($rest->input->status)->toBe(OrderStatus::Delivered)
            ->and($transit->input->status)->toBe(OrderStatus::Shipped)
            ->and($cancelled->input->status)->toBe(OrderStatus::Cancelled)
            ->and($graphql->input->status)->toBe(OrderStatus::Delivered);
    });

    it('finds the phone wherever the checkout put it, and refuses an order without one', function (): void {
        $p = shopifyFixture();
        $p['shipping_address']['phone'] = 'n/a';
        $p['customer']['phone'] = '+201098765432';
        expect((new ShopifyOrderMapper)->fromWebhook($p)->input->customerPhone)->toBe('201098765432');

        $p['customer']['phone'] = null;
        try {
            (new ShopifyOrderMapper)->fromWebhook($p);
            $this->fail('expected UnmappableOrder');
        } catch (UnmappableOrder $e) {
            expect($e->reason)->toBe('no-phone');
        }
    });

    it('refuses an order with every line edited out, and clamps an oversized discount', function (): void {
        $p = shopifyFixture();
        expect(fn () => (new ShopifyOrderMapper)->fromWebhook([...$p, 'line_items' => [['title' => 'x', 'quantity' => 1, 'current_quantity' => 0, 'price' => '5']]]))
            ->toThrow(UnmappableOrder::class);

        $gift = (new ShopifyOrderMapper)->fromWebhook([...$p, 'current_total_discounts' => '99999.00', 'current_total_price' => '0.00']);
        expect($gift->input->discount)->toBe(156000)->and($gift->input->grandTotal())->toBe(0);
    });

    it('leaves the governorate empty for a code it does not know, or another country', function (): void {
        $p = shopifyFixture();
        $p['shipping_address']['province_code'] = 'XX';
        expect((new ShopifyOrderMapper)->fromWebhook($p)->input->address->governorate)->toBeNull();

        $p['shipping_address'] = [...$p['shipping_address'], 'province_code' => 'C', 'country_code' => 'SA'];
        expect((new ShopifyOrderMapper)->fromWebhook($p)->input->address->governorate)->toBeNull();
    });

    it('maps the same order from GraphQL (backfill, bulk line items apart)', function (): void {
        $node = [
            'id' => 'gid://shopify/Order/5820117942336', 'legacyResourceId' => '5820117942336', 'name' => '#1048',
            'createdAt' => '2026-11-27T11:05:11Z', 'updatedAt' => '2026-11-27T11:05:14Z', 'cancelledAt' => null,
            'displayFinancialStatus' => 'PENDING', 'displayFulfillmentStatus' => 'UNFULFILLED', 'currencyCode' => 'EGP',
            'currentTotalPriceSet' => ['shopMoney' => ['amount' => '1460.0']], 'totalShippingPriceSet' => ['shopMoney' => ['amount' => '60.0']],
            'currentTotalDiscountsSet' => ['shopMoney' => ['amount' => '100.0']], 'note' => null, 'phone' => null,
            'customer' => ['firstName' => 'Mona', 'lastName' => 'Adel', 'phone' => null],
            'shippingAddress' => ['name' => 'منى عادل', 'phone' => '01012345678', 'address1' => '12 شارع التحرير', 'address2' => null, 'city' => 'الدقي', 'province' => 'Giza', 'provinceCode' => 'GZ', 'countryCodeV2' => 'EG'],
            'billingAddress' => null,
            'fulfillments' => [['trackingInfo' => [['company' => 'Aramex', 'number' => 'AX1', 'url' => null]]]],
        ];
        $lines = [
            ['id' => 'gid://shopify/LineItem/14001', 'title' => 'فستان صيفي', 'variantTitle' => 'M / أزرق', 'sku' => 'DRS-01', 'quantity' => 2, 'currentQuantity' => 2, 'originalUnitPriceSet' => ['shopMoney' => ['amount' => '450.0']], '__parentId' => 'gid://shopify/Order/5820117942336'],
            ['id' => 'gid://shopify/LineItem/14002', 'title' => 'حزام جلد', 'variantTitle' => null, 'sku' => null, 'quantity' => 1, 'currentQuantity' => 1, 'originalUnitPriceSet' => ['shopMoney' => ['amount' => '600.0']]],
        ];

        $bulk = (new ShopifyOrderMapper)->fromGraphql($node, $lines);
        $nested = (new ShopifyOrderMapper)->fromGraphql([...$node, 'lineItems' => ['edges' => array_map(fn ($l) => ['node' => $l], $lines)]]);
        $webhook = (new ShopifyOrderMapper)->fromWebhook(shopifyFixture());

        expect($bulk->externalId)->toBe('5820117942336')
            ->and($bulk->input->items[0]->externalId)->toBe('14001')
            ->and($bulk->input->grandTotal())->toBe($webhook->input->grandTotal())
            ->and($bulk->input->customerPhone)->toBe($webhook->input->customerPhone)
            ->and($bulk->input->address->governorate?->value)->toBe('GZ')
            ->and($bulk->input->courier)->toBe('Aramex')
            ->and($bulk->updatedAt == $webhook->updatedAt)->toBeTrue() // same instant, other offset
            ->and($nested->input->items)->toEqual($bulk->input->items);
    });
});
