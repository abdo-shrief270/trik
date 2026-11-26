<?php

declare(strict_types=1);

namespace Modules\Workspaces\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Workspaces\Domain\WorkspaceRole;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property WorkspaceRole $role
 * @property string|null $custom_role_id
 * @property Carbon|null $created_at
 * @property-read Workspace $workspace
 * @property-read CustomRole|null $customRole
 */
final class WorkspaceMember extends Model
{
    use HasUuids;

    protected $table = 'workspaces_members';

    protected $fillable = ['workspace_id', 'user_id', 'role', 'custom_role_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => WorkspaceRole::class];
    }

    /** @return BelongsTo<CustomRole, $this> */
    public function customRole(): BelongsTo
    {
        return $this->belongsTo(CustomRole::class, 'custom_role_id');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
