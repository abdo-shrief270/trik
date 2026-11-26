<?php

declare(strict_types=1);

namespace Modules\Workspaces\Application;

use Modules\Workspaces\Domain\WorkspaceRole;

/** What other modules get to see of a workspace. */
final readonly class WorkspaceData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public WorkspaceRole $role,
    ) {}

    /** @return array{id: string, name: string, slug: string, role: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'slug' => $this->slug, 'role' => $this->role->value];
    }
}
