<?php

declare(strict_types=1);

namespace Modules\Addresses\Application;

use Illuminate\Validation\Rule;
use Modules\Addresses\Domain\Governorate;

/** Request validation for an address, shared by every endpoint that takes one. */
final class AddressRules
{
    /** @return array<string, list<mixed>> rules for an address under $prefix (e.g. 'address') */
    public static function for(string $prefix): array
    {
        return [
            $prefix => ['array'],
            "{$prefix}.raw" => ['nullable', 'string', 'max:500'],
            "{$prefix}.governorate" => ['nullable', 'string', Rule::enum(Governorate::class)],
            "{$prefix}.area" => ['nullable', 'string', 'max:120'],
            "{$prefix}.street" => ['nullable', 'string', 'max:200'],
            "{$prefix}.building" => ['nullable', 'string', 'max:40'],
            "{$prefix}.floor" => ['nullable', 'string', 'max:20'],
            "{$prefix}.apartment" => ['nullable', 'string', 'max:20'],
            "{$prefix}.landmark" => ['nullable', 'string', 'max:200'],
        ];
    }
}
