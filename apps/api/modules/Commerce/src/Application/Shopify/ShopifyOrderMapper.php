<?php

declare(strict_types=1);

namespace Modules\Commerce\Application\Shopify;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Addresses\Domain\Address;
use Modules\Addresses\Domain\Governorate;
use Modules\Commerce\Domain\Money;
use Modules\Orders\Application\OrderInput;
use Modules\Orders\Application\OrderItemInput;
use Modules\Orders\Domain\OrderSource;
use Modules\Orders\Domain\OrderStatus;
use Modules\WhatsApp\Domain\PhoneNumber;

/**
 * A Shopify order, as a webhook (REST JSON) or the GraphQL Admin API (backfill, refresh) hands it over,
 * into the one shape RecordOrder takes. Both shapes are first normalised, so the rules live in one place:
 *  - phone: shipping address, then billing, then customer, then order (COD stores collect it at checkout);
 *  - payment: paid / authorized / refunded is prepaid, anything else is cash on delivery;
 *  - status: cancelled → cancelled, fulfilled → shipped; otherwise left to Trik (confirmation owns it);
 *  - quantities after edits (current_quantity), lines edited down to 0 dropped;
 *  - address: the full text in `raw`, plus governorate (Shopify's Egyptian province codes are the
 *    ISO 3166-2:EG suffixes Trik uses), area = city, street = address1.
 */
final class ShopifyOrderMapper
{
    /** @param array<string, mixed> $p a webhook body (orders/create, orders/updated, orders/cancelled) */
    public function fromWebhook(array $p): MappedOrder
    {
        $ship = $this->arr($p['shipping_address'] ?? null);
        $bill = $this->arr($p['billing_address'] ?? null);
        $customer = $this->arr($p['customer'] ?? null);

        $items = [];
        foreach ($this->list($p['line_items'] ?? null) as $l) {
            $items[] = [
                'title' => $this->str($l['title'] ?? $l['name'] ?? null),
                'variant' => $this->str($l['variant_title'] ?? null),
                'sku' => $this->str($l['sku'] ?? null),
                'quantity' => (int) ($l['current_quantity'] ?? $l['quantity'] ?? 0),
                'price' => $this->scalar($l['price'] ?? null),
                'id' => $this->str($l['id'] ?? null),
            ];
        }

        $tracking = null;
        foreach ($this->list($p['fulfillments'] ?? null) as $f) {
            $numbers = is_array($f['tracking_numbers'] ?? null) ? array_values($f['tracking_numbers']) : [];
            $number = $this->str($f['tracking_number'] ?? null) ?? $this->str($numbers[0] ?? null);
            if ($number !== null || $this->str($f['tracking_company'] ?? null) !== null) {
                $tracking = ['company' => $this->str($f['tracking_company'] ?? null), 'number' => $number, 'url' => $this->str($f['tracking_url'] ?? null)];
            }
        }

        return $this->build([
            'id' => $this->str($p['id'] ?? null),
            'name' => $this->str($p['name'] ?? null),
            'created_at' => $this->str($p['created_at'] ?? null),
            'updated_at' => $this->str($p['updated_at'] ?? null),
            'cancelled' => $this->str($p['cancelled_at'] ?? null) !== null,
            'financial' => strtolower((string) $this->str($p['financial_status'] ?? null)),
            'fulfillment' => strtolower((string) $this->str($p['fulfillment_status'] ?? null)),
            'currency' => $this->str($p['currency'] ?? null),
            'total' => $this->scalar($p['current_total_price'] ?? $p['total_price'] ?? null),
            'shipping' => $this->scalar(data_get($p, 'total_shipping_price_set.shop_money.amount')),
            'discount' => $this->scalar($p['current_total_discounts'] ?? $p['total_discounts'] ?? null),
            'note' => $this->str($p['note'] ?? null),
            'phones' => [$ship['phone'] ?? null, $bill['phone'] ?? null, $customer['phone'] ?? null, $p['phone'] ?? null],
            'names' => [$ship['name'] ?? null, trim(($customer['first_name'] ?? '').' '.($customer['last_name'] ?? '')), $bill['name'] ?? null],
            'address' => [
                'address1' => $ship['address1'] ?? null, 'address2' => $ship['address2'] ?? null, 'city' => $ship['city'] ?? null,
                'province' => $ship['province'] ?? null, 'province_code' => $ship['province_code'] ?? null, 'country_code' => $ship['country_code'] ?? null,
            ],
            'items' => $items,
            'tracking' => $tracking,
        ]);
    }

    /**
     * A GraphQL Order node (see ShopifyQueries::ORDER_FIELDS) and its line items: from a bulk operation
     * the line items arrive as separate JSONL lines; from a normal query, under lineItems.edges.
     *
     * @param  array<string, mixed>  $node
     * @param  list<array<string, mixed>>|null  $lineItems
     */
    public function fromGraphql(array $node, ?array $lineItems = null): MappedOrder
    {
        $lineItems ??= array_map(fn (array $e): array => $this->arr($e['node'] ?? null), $this->list(data_get($node, 'lineItems.edges')));
        $ship = $this->arr($node['shippingAddress'] ?? null);
        $customer = $this->arr($node['customer'] ?? null);

        $items = [];
        foreach ($lineItems as $l) {
            $items[] = [
                'title' => $this->str($l['title'] ?? $l['name'] ?? null),
                'variant' => $this->str($l['variantTitle'] ?? null),
                'sku' => $this->str($l['sku'] ?? null),
                'quantity' => (int) ($l['currentQuantity'] ?? $l['quantity'] ?? 0),
                'price' => $this->scalar(data_get($l, 'originalUnitPriceSet.shopMoney.amount')),
                'id' => $this->gidNumber($this->str($l['id'] ?? null)),
            ];
        }

        $tracking = null;
        foreach ($this->list($node['fulfillments'] ?? null) as $f) {
            foreach ($this->list($f['trackingInfo'] ?? null) as $t) {
                if ($this->str($t['number'] ?? null) !== null || $this->str($t['company'] ?? null) !== null) {
                    $tracking = ['company' => $this->str($t['company'] ?? null), 'number' => $this->str($t['number'] ?? null), 'url' => $this->str($t['url'] ?? null)];
                }
            }
        }

        return $this->build([
            'id' => $this->str($node['legacyResourceId'] ?? null) ?? $this->gidNumber($this->str($node['id'] ?? null)),
            'name' => $this->str($node['name'] ?? null),
            'created_at' => $this->str($node['createdAt'] ?? null),
            'updated_at' => $this->str($node['updatedAt'] ?? null),
            'cancelled' => $this->str($node['cancelledAt'] ?? null) !== null,
            'financial' => strtolower((string) $this->str($node['displayFinancialStatus'] ?? null)),
            'fulfillment' => strtolower((string) $this->str($node['displayFulfillmentStatus'] ?? null)),
            'currency' => $this->str($node['currencyCode'] ?? null),
            'total' => $this->scalar(data_get($node, 'currentTotalPriceSet.shopMoney.amount') ?? data_get($node, 'totalPriceSet.shopMoney.amount')),
            'shipping' => $this->scalar(data_get($node, 'totalShippingPriceSet.shopMoney.amount')),
            'discount' => $this->scalar(data_get($node, 'currentTotalDiscountsSet.shopMoney.amount') ?? data_get($node, 'totalDiscountsSet.shopMoney.amount')),
            'note' => $this->str($node['note'] ?? null),
            'phones' => [$ship['phone'] ?? null, data_get($node, 'billingAddress.phone'), $customer['phone'] ?? null, $node['phone'] ?? null],
            'names' => [$ship['name'] ?? null, trim(($customer['firstName'] ?? '').' '.($customer['lastName'] ?? '')), data_get($node, 'billingAddress.name')],
            'address' => [
                'address1' => $ship['address1'] ?? null, 'address2' => $ship['address2'] ?? null, 'city' => $ship['city'] ?? null,
                'province' => $ship['province'] ?? null, 'province_code' => $ship['provinceCode'] ?? null, 'country_code' => $ship['countryCodeV2'] ?? null,
            ],
            'items' => $items,
            'tracking' => $tracking,
        ]);
    }

    /** @param array<string, mixed> $o the normalised order */
    private function build(array $o): MappedOrder
    {
        $id = $o['id'];
        if (! is_string($id) || $id === '') {
            throw new UnmappableOrder('no-id', 'The order has no id.');
        }

        $items = [];
        foreach ((array) $o['items'] as $i) {
            if ($i['quantity'] < 1) {
                continue; // removed by an order edit
            }
            $items[] = new OrderItemInput(
                mb_substr($i['title'] ?? 'Item', 0, 255), $i['quantity'], max(0, $this->money($i['price'])), $i['sku'], $i['variant'], $i['id'],
            );
        }
        if ($items === []) {
            throw new UnmappableOrder('no-items', 'The order has no items left.');
        }

        $phone = $this->phone(array_values((array) $o['phones']));
        if ($phone === null) {
            throw new UnmappableOrder('no-phone', 'The order has no usable phone number, so it cannot be confirmed on WhatsApp.');
        }

        $subtotal = array_sum(array_map(fn (OrderItemInput $i): int => $i->total(), $items));
        $shipping = max(0, $this->money($o['shipping']));
        // a gift card or 100% code can push the discount past the items; never below zero
        $discount = min(max(0, $this->money($o['discount'])), $subtotal + $shipping);
        $total = $o['total'] === null ? null : max(0, $this->money($o['total']));

        $status = match (true) {
            $o['cancelled'] === true => OrderStatus::Cancelled,
            $o['fulfillment'] === 'fulfilled' => OrderStatus::Shipped,
            default => null,
        };
        $prepaid = in_array($o['financial'], ['paid', 'partially_refunded', 'refunded', 'authorized'], true);

        $tracking = is_array($o['tracking']) ? $o['tracking'] : [];

        return new MappedOrder(
            new OrderInput(
                source: OrderSource::Shopify,
                externalId: $id,
                number: $o['name'] ?? '#'.$id,
                customerPhone: $phone,
                customerName: $this->firstName(array_values((array) $o['names'])),
                address: $this->address((array) $o['address']),
                items: $items,
                placedAt: $this->date($o['created_at']) ?? new DateTimeImmutable,
                shipping: $shipping,
                discount: $discount,
                total: $total,
                paymentMethod: $prepaid ? 'prepaid' : 'cod',
                currency: is_string($o['currency']) && preg_match('/^[A-Z]{3}$/', $o['currency']) === 1 ? $o['currency'] : 'EGP',
                status: $status,
                note: $o['note'] === null ? null : mb_substr($o['note'], 0, 2000),
                courier: $tracking['company'] ?? null,
                trackingNumber: $tracking['number'] ?? null,
                trackingUrl: $tracking['url'] ?? null,
            ),
            $id,
            $this->date($o['updated_at']) ?? $this->date($o['created_at']) ?? new DateTimeImmutable,
        );
    }

    /** @param array<string, mixed> $a */
    private function address(array $a): Address
    {
        $s = fn (string $k): ?string => $this->str($a[$k] ?? null);
        $raw = implode('، ', array_values(array_filter([$s('address1'), $s('address2'), $s('city'), $s('province')], fn (?string $v): bool => $v !== null)));
        $egypt = in_array($s('country_code'), ['EG', null], true);

        return new Address(
            raw: $raw === '' ? null : mb_substr($raw, 0, 500),
            governorate: $egypt && $s('province_code') !== null ? Governorate::tryFrom(strtoupper((string) $s('province_code'))) : null,
            area: $s('city') === null ? null : mb_substr((string) $s('city'), 0, 120),
            street: $s('address1') === null ? null : mb_substr((string) $s('address1'), 0, 200),
        );
    }

    /** @param list<mixed> $candidates */
    private function phone(array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (! is_string($c) || trim($c) === '') {
                continue;
            }
            try {
                return PhoneNumber::fromString($c)->digits;
            } catch (InvalidArgumentException) {
                // try the next one
            }
        }

        return null;
    }

    /** @param list<mixed> $names */
    private function firstName(array $names): ?string
    {
        foreach ($names as $n) {
            if (is_string($n) && trim($n) !== '') {
                return mb_substr(trim($n), 0, 255);
            }
        }

        return null;
    }

    private function money(mixed $amount): int
    {
        try {
            return is_string($amount) || is_int($amount) || is_float($amount) ? Money::minor($amount) : 0;
        } catch (InvalidArgumentException) {
            return 0;
        }
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /** "gid://shopify/LineItem/123" → "123". */
    private function gidNumber(?string $gid): ?string
    {
        return $gid === null ? null : (preg_match('#/(\d+)$#', $gid, $m) === 1 ? $m[1] : $gid);
    }

    private function str(mixed $v): ?string
    {
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    private function scalar(mixed $v): string|int|float|null
    {
        return is_string($v) || is_int($v) || is_float($v) ? $v : null;
    }

    /** @return array<string, mixed> */
    private function arr(mixed $v): array
    {
        return is_array($v) ? $v : [];
    }

    /** @return list<array<string, mixed>> */
    private function list(mixed $v): array
    {
        return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
    }
}
