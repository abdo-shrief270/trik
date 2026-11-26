<?php

declare(strict_types=1);

namespace Modules\Workspaces\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Workspaces\Domain\WorkspaceRole;
use Modules\Workspaces\Infrastructure\Models\Workspace;
use Modules\Workspaces\Infrastructure\Models\WorkspaceMember;

/** Creates a workspace and makes the given user its owner. */
final class CreateWorkspace
{
    public function handle(string $name, string $ownerId): WorkspaceData
    {
        return DB::transaction(function () use ($name, $ownerId): WorkspaceData {
            $workspace = Workspace::query()->create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'owner_id' => $ownerId,
            ]);

            WorkspaceMember::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $ownerId,
                'role' => WorkspaceRole::Owner,
            ]);

            return new WorkspaceData($workspace->id, $workspace->name, $workspace->slug, WorkspaceRole::Owner);
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;

        while (Workspace::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
