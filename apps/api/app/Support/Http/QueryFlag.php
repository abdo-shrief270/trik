<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * Validation for a yes/no query-string filter (`?unread=true`, `?needs_address_fix=1`).
 *
 * The contract types these as booleans, so generated clients send `true` / `false`. Laravel's `boolean` rule
 * rejects those strings (it only knows real booleans and 1/0), so query flags use this rule instead and are read
 * with `$request->boolean()`, which understands both spellings.
 */
final class QueryFlag
{
    /** @return list<string> */
    public static function rules(): array
    {
        return ['sometimes', 'in:0,1,true,false'];
    }
}
