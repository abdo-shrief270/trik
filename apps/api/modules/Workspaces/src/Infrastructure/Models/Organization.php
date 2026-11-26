<?php

declare(strict_types=1);

namespace Modules\Workspaces\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A business owner's group of workspaces (brands). ADR 0012.
 *
 * @property string $id
 * @property string $name
 */
final class Organization extends Model
{
    use HasUuids;

    protected $table = 'workspaces_organizations';

    protected $fillable = ['name'];

    /** @return HasMany<Workspace, $this> */
    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    /** @return HasMany<OrganizationMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }
}
