<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Domain;

use InvalidArgumentException;

/**
 * A phone number in international form, stored as digits only (WhatsApp's wa_id format).
 * Local numbers starting with 0 get the default country code: 01012345678 → 201012345678.
 */
final readonly class PhoneNumber
{
    private function __construct(public string $digits) {}

    public static function fromString(string $raw, string $defaultCountryCode = '20'): self
    {
        $trimmed = trim($raw);
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if (str_starts_with($trimmed, '00')) {
            $digits = substr($digits, 2);
        } elseif (! str_starts_with($trimmed, '+') && str_starts_with($digits, '0')) {
            $digits = $defaultCountryCode.substr($digits, 1);
        }

        if (preg_match('/^[1-9]\d{7,14}$/', $digits) !== 1) {
            throw new InvalidArgumentException("Not a valid phone number: {$raw}");
        }

        return new self($digits);
    }

    public function e164(): string
    {
        return '+'.$this->digits;
    }

    public function equals(self $other): bool
    {
        return $this->digits === $other->digits;
    }
}
