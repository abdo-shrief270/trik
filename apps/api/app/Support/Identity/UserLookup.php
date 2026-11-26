<?php

declare(strict_types=1);

namespace App\Support\Identity;

/** Implemented by the Identity module, so other modules can show names without touching its tables. */
interface UserLookup
{
    /**
     * @param  list<string>  $ids
     * @return array<string, array{id: string, name: string, email: string}>
     */
    public function find(array $ids): array;
}
