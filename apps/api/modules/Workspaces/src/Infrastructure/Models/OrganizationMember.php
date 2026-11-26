<?php

declare(strict_types=1);

namespace Modules\Workspaces\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Workspaces\Domain\OrganizationRole;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property OrganizationRole $role
 * @property-read Organization $organization
 */
final class OrganizationMember extends Model
{
    use HasUuids;

    protected $table = 'workspaces_organization_members';

    protected $fillable = ['organization_id', 'user_id', 'role'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => OrganizationRole::class];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
