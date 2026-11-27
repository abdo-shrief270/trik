<?php

declare(strict_types=1);

namespace App\Support\Privacy;

/**
 * Whose personal data a privacy request is about, inside one workspace.
 *
 *  - phones:      E.164 digits ("201012345678"), the customer's identity across Trik;
 *  - orderSource: where the request came from ("shopify"); order redaction stays within that source;
 *  - orderIds:    the source's own order ids (Shopify order ids);
 *  - storeId:     the connected store, when the request is about a store;
 *  - wholeStore:  every customer of that store (Shopify's shop/redact).
 *
 * Sources widen it with expand() before exporting or redacting (e.g. Orders adds the phones on the
 * subject's orders, so a Shopify request that names only orders still reaches the contact).
 */
final readonly class DataSubject
{
    /**
     * @param  list<string>  $phones
     * @param  list<string>  $orderIds
     */
    public function __construct(
        public array $phones = [],
        public ?string $orderSource = null,
        public array $orderIds = [],
        public ?string $storeId = null,
        public bool $wholeStore = false,
    ) {}

    /** @param list<string> $phones */
    public function withPhones(array $phones): self
    {
        return new self(array_values(array_unique([...$this->phones, ...$phones])), $this->orderSource, $this->orderIds, $this->storeId, $this->wholeStore);
    }

    /** @param list<string> $orderIds */
    public function withOrderIds(array $orderIds): self
    {
        return new self($this->phones, $this->orderSource, array_values(array_unique([...$this->orderIds, ...$orderIds])), $this->storeId, $this->wholeStore);
    }

    public function isEmpty(): bool
    {
        return $this->phones === [] && $this->orderIds === [] && ! $this->wholeStore;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['phones' => $this->phones, 'order_source' => $this->orderSource, 'order_ids' => $this->orderIds, 'store_id' => $this->storeId, 'whole_store' => $this->wholeStore];
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $strings = fn (mixed $v): array => is_array($v) ? array_values(array_map('strval', array_filter($v, 'is_scalar'))) : [];

        return new self(
            $strings($a['phones'] ?? []),
            is_string($a['order_source'] ?? null) ? $a['order_source'] : null,
            $strings($a['order_ids'] ?? []),
            is_string($a['store_id'] ?? null) ? $a['store_id'] : null,
            ($a['whole_store'] ?? false) === true,
        );
    }
}
