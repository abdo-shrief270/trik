<?php

declare(strict_types=1);

namespace Modules\Workspaces\Infrastructure;

use App\Support\Authorization\PermissionRegistry;
use Illuminate\Auth\Access\Response;
use Modules\Workspaces\Application\ActiveOrganization;

/** Gate::before for `organization.*` abilities: answered from the role in the active organization. */
final class OrganizationGate
{
    private PermissionRegistry $catalogue;

    public function __construct(private readonly ActiveOrganization $active)
    {
        $this->catalogue = OrganizationPermissions::registry();
    }

    public function before(string $ability): ?Response
    {
        if (! $this->catalogue->has($ability)) {
            return null;
        }

        $role = $this->active->role();

        if ($role !== null && $this->catalogue->allows($role->grants(), $ability)) {
            return Response::allow();
        }

        return Response::deny("You need the {$ability} permission in this organization.", 'missing-permission');
    }
}
