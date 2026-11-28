<?php

declare(strict_types=1);

namespace Modules\Commerce\Application\EasyOrders;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use Modules\Addresses\Domain\Address;
use Modules\Addresses\Domain\Governorate;
use Modules\Commerce\Application\Shopify\MappedOrder;
use Modules\Commerce\Application\Shopify\UnmappableOrder;
use Modules\Commerce\Domain\Money;
use Modules\Orders\Application\OrderInput;
use Modules\Orders\Application\OrderItemInput;
use Modules\Orders\Domain\OrderSource;
use Modules\Orders\Domain\OrderStatus;
use Modules\WhatsApp\Domain\PhoneNumber;

/**
 * An EasyOrders order (GET /orders/:id, the same shape as the order-created webhook) into RecordOrder's
 * shape. Field names as documented: full_name, phone, government (free text), address, cost,
 * shipping_cost, total_cost, payment_method, status, cart_items[price, quantity, product, variant].
 */
final class EasyOrdersOrderMapper
{
    /** EasyOrders statuses that say something Trik tracks; the rest (pending, payment states) leave it to Trik. */
    private const STATUS = [
        'confirmed' => OrderStatus::Confirmed,
        'processing' => OrderStatus::ReadyToShip,
        'waiting_for_pickup' => OrderStatus::ReadyToShip,
        'in_delivery' => OrderStatus::Shipped,
        'delivered' => OrderStatus::Delivered,
        'canceled' => OrderStatus::Cancelled,
        'cancelled' => OrderStatus::Cancelled,
        'returning_from_delivery' => OrderStatus::Returned,
        'request_refund' => OrderStatus::Returned,
        'refund_in_progress' => OrderStatus::Returned,
        'refunded' => OrderStatus::Returned,
    ];

    /** @param array<string, mixed> $o */
    public function fromApi(array $o): MappedOrder
    {
        $id = $this->str($o['id'] ?? null);
        if ($id === null) {
            throw new UnmappableOrder('no-id', 'The order has no id.');
        }

        $items = [];
        foreach (is_array($o['cart_items'] ?? null) ? $o['cart_items'] : [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $quantity = (int) ($line['quantity'] ?? 0);
            if ($quantity < 1) {
                continue;
            }
            $product = is_array($line['product'] ?? null) ? $line['product'] : [];
            $items[] = new OrderItemInput(
                mb_substr($this->str($product['name'] ?? null) ?? 'Item', 0, 255),
                $quantity,
                max(0, $this->money($line['price'] ?? $product['price'] ?? null)),
                $this->str($product['sku'] ?? null),
                $this->variant($line['variant'] ?? null),
                $this->str($line['id'] ?? null),
            );
        }
        if ($items === []) {
            throw new UnmappableOrder('no-items', 'The order has no items.');
        }

        try {
            $phone = PhoneNumber::fromString((string) $this->str($o['phone'] ?? null))->digits;
        } catch (InvalidArgumentException) {
            throw new UnmappableOrder('no-phone', 'The order has no usable phone number, so it cannot be confirmed on WhatsApp.');
        }

        $subtotal = array_sum(array_map(fn (OrderItemInput $i): int => $i->total(), $items));
        $shipping = max(0, $this->money($o['shipping_cost'] ?? null));
        $total = ($o['total_cost'] ?? null) === null ? null : max(0, $this->money($o['total_cost']));
        $discount = $total === null ? 0 : min(max(0, $subtotal + $shipping - $total), $subtotal + $shipping);

        $status = strtolower((string) $this->str($o['status'] ?? null));
        $method = strtolower((string) ($this->str($o['payment_method'] ?? null) ?? 'cod'));
        $prepaid = $status === 'paid' || $method !== 'cod';

        $government = $this->str($o['government'] ?? null);
        $street = $this->str($o['address'] ?? null);
        $raw = implode('، ', array_values(array_filter([$street, $government], fn (?string $v): bool => $v !== null)));
        $short = $this->str($o['short_id'] ?? null);

        return new MappedOrder(
            new OrderInput(
                source: OrderSource::EasyOrders,
                externalId: $id,
                number: $short !== null ? '#'.$short : '#'.strtoupper(substr($id, 0, 8)),
                customerPhone: $phone,
                customerName: ($name = $this->str($o['full_name'] ?? null)) === null ? null : mb_substr($name, 0, 255),
                address: new Address(raw: $raw === '' ? null : mb_substr($raw, 0, 500), governorate: Governorate::fromName($government)),
                items: $items,
                placedAt: $this->date($o['created_at'] ?? null) ?? new DateTimeImmutable,
                shipping: $shipping,
                discount: $discount,
                total: $total,
                paymentMethod: $prepaid ? 'prepaid' : 'cod',
                status: self::STATUS[$status] ?? null,
            ),
            $id,
            $this->date($o['updated_at'] ?? null) ?? $this->date($o['created_at'] ?? null) ?? new DateTimeImmutable,
        );
    }

    /** "color: #808080 · size: L" from variation_props. */
    private function variant(mixed $variant): ?string
    {
        if (! is_array($variant) || ! is_array($variant['variation_props'] ?? null)) {
            return null;
        }
        $parts = [];
        foreach ($variant['variation_props'] as $p) {
            if (is_array($p) && ($value = $this->str($p['variation_prop'] ?? null)) !== null) {
                $parts[] = ($label = $this->str($p['variation'] ?? null)) === null ? $value : "{$label}: {$value}";
            }
        }

        return $parts === [] ? null : mb_substr(implode(' · ', $parts), 0, 255);
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
        } catch (Exception) {
            return null;
        }
    }

    private function str(mixed $v): ?string
    {
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
