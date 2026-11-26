<?php

declare(strict_types=1);

namespace Modules\Workspaces\Application;

use App\Support\Http\Problem;
use Modules\Workspaces\Domain\OrganizationRole;

/** The organization named in X-Organization-Id for this request, and the caller's role in it. */
final class ActiveOrganization
{
    private ?string $id = null;

    private ?OrganizationRole $role = null;

    public function set(string $id, OrganizationRole $role): void
    {
        $this->id = $id;
        $this->role = $role;
    }

    public function forget(): void
    {
        $this->id = null;
        $this->role = null;
    }

    public function id(): string
    {
        return $this->id ?? throw Problem::badRequest('organization-header-missing', 'Send the organization id in the X-Organization-Id header.');
    }

    public function role(): ?OrganizationRole
    {
        return $this->role;
    }
}
