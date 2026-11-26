<?php

declare(strict_types=1);

namespace Modules\Workspaces\Application;

use Illuminate\Support\Facades\DB;
use Modules\Workspaces\Domain\OrganizationRole;
use Modules\Workspaces\Infrastructure\Models\Organization;
use Modules\Workspaces\Infrastructure\Models\OrganizationMember;

/** Creates an organization owned by the given user. */
final class CreateOrganization
{
    public function handle(string $name, string $ownerId): OrganizationData
    {
        return DB::transaction(function () use ($name, $ownerId): OrganizationData {
            $organization = Organization::query()->create(['name' => $name]);
            OrganizationMember::query()->create(['organization_id' => $organization->id, 'user_id' => $ownerId, 'role' => OrganizationRole::Owner]);

            return new OrganizationData($organization->id, $organization->name, OrganizationRole::Owner, []);
        });
    }
}
