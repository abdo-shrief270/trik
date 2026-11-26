<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Hooked into Gate::before. Any ability that is a registered permission is answered from the
 * member's grants in the active workspace; other abilities fall through to normal policies.
 *
 *     Route::post(...)->middleware('can:whatsapp.numbers.manage');
 *     $user->can('whatsapp.messages.send');
 */
final class PermissionGate
{
    /** @var array<string, MemberAccess|null> */
    private array $memo = [];

    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly WorkspacePermissions $permissions,
        private readonly TenantContext $context,
    ) {}

    public function before(?Authenticatable $user, string $ability): ?Response
    {
        if (! $this->registry->has($ability)) {
            return null;
        }

        $access = $this->current($user);

        if ($access !== null && $this->registry->allows($access->grants, $ability)) {
            return Response::allow();
        }

        return Response::deny("You need the {$ability} permission in this workspace.", 'missing-permission');
    }

    public function current(?Authenticatable $user): ?MemberAccess
    {
        $workspaceId = $this->context->id();

        if ($user === null || $workspaceId === null) {
            return null;
        }

        $key = $workspaceId.'|'.$user->getAuthIdentifier();

        return array_key_exists($key, $this->memo)
            ? $this->memo[$key]
            : $this->memo[$key] = $this->permissions->access($workspaceId, (string) $user->getAuthIdentifier());
    }

    public function forget(): void
    {
        $this->memo = [];
    }
}
