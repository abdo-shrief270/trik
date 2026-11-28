<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure;

use App\Support\Identity\EmailVerifier;
use App\Support\Identity\UserLookup;
use Modules\Identity\Infrastructure\Models\User;

final class EloquentUserLookup implements EmailVerifier, UserLookup
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

    public function findByEmail(string $email): ?string
    {
        $id = User::query()->where('email', mb_strtolower(trim($email)))->value('id');

        return is_string($id) ? $id : null;
    }

    public function markVerified(string $userId): void
    {
        User::query()->whereKey($userId)->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }
}
