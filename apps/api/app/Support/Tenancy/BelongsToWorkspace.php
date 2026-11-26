<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Model;

/**
 * Use on every Eloquent model whose table has a workspace_id column.
 * Reads are filtered to the active workspace, new rows are stamped with it,
 * and both throw MissingWorkspaceContext when no workspace is active.
 *
 * @mixin Model
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function (Model $model): void {
            $model->setAttribute('workspace_id', app(TenantContext::class)->require());
        });
    }
}
