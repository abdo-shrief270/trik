<?php

declare(strict_types=1);

namespace Modules\Workspaces\Infrastructure\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string|null $description
 * @property list<string> $permissions
 */
final class CustomRole extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    protected $table = 'workspaces_roles';

    protected $fillable = ['name', 'description', 'permissions'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'description' => $this->description, 'permissions' => $this->permissions, 'built_in' => false];
    }
}
