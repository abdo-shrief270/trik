<?php

declare(strict_types=1);

namespace Modules\Workspaces\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Workspaces\Infrastructure\Factories\WorkspaceFactory;

/**
 * A seller's workspace: the tenant boundary.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $owner_id
 */
final class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['name', 'slug', 'owner_id'];

    /** @return HasMany<WorkspaceMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    protected static function newFactory(): WorkspaceFactory
    {
        return WorkspaceFactory::new();
    }
}
