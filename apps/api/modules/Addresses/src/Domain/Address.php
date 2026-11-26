<?php

declare(strict_types=1);

namespace Modules\Addresses\Domain;

/**
 * A delivery address in the structured form couriers need (#344). `raw` keeps the address as the
 * store or the customer wrote it; the structured fields are filled by an agent (or, later, #109).
 */
final readonly class Address
{
    public function __construct(
        public ?string $raw = null,
        public ?Governorate $governorate = null,
        public ?string $area = null,
        public ?string $street = null,
        public ?string $building = null,
        public ?string $floor = null,
        public ?string $apartment = null,
        public ?string $landmark = null,
    ) {}

    /** @param array<string, mixed>|null $data */
    public static function fromArray(?array $data): self
    {
        $s = fn (string $k): ?string => isset($data[$k]) && is_scalar($data[$k]) && trim((string) $data[$k]) !== '' ? trim((string) $data[$k]) : null;
        $g = $s('governorate');

        return new self($s('raw'), $g === null ? null : Governorate::tryFrom($g), $s('area'), $s('street'), $s('building'), $s('floor'), $s('apartment'), $s('landmark'));
    }

    /** Enough for a courier: governorate, area and street. */
    public function isDeliverable(): bool
    {
        return $this->governorate !== null && $this->area !== null && $this->street !== null;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'raw' => $this->raw,
            'governorate' => $this->governorate?->value,
            'area' => $this->area,
            'street' => $this->street,
            'building' => $this->building,
            'floor' => $this->floor,
            'apartment' => $this->apartment,
            'landmark' => $this->landmark,
        ];
    }

    /** One line for people (Arabic), e.g. "12 شارع التحرير، الدقي، الجيزة — بجوار البنك". */
    public function line(): ?string
    {
        if ($this->governorate === null && $this->street === null) {
            return $this->raw;
        }

        $parts = array_filter([
            trim(implode(' ', array_filter([$this->building, $this->street]))),
            $this->area,
            $this->governorate?->nameAr(),
        ]);
        $line = implode('، ', $parts);

        return $this->landmark !== null ? "{$line} — {$this->landmark}" : $line;
    }
}
