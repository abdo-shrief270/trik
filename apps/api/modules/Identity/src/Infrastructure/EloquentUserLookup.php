<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure;

use App\Support\Identity\UserLookup;
use Modules\Identity\Infrastructure\Models\User;

final class EloquentUserLookup implements UserLookup
{
    public function find(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->get(['id', 'name', 'email'])
            ->mapWithKeys(fn (User $u): array => [$u->id => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]])
            ->all();
    }
}
