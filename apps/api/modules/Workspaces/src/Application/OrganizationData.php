<?php

declare(strict_types=1);

namespace Modules\Workspaces\Application;

use Modules\Workspaces\Domain\OrganizationRole;

/** An organization as one user sees it: their organization role and only their workspaces. */
final readonly class OrganizationData
{
    /** @param list<WorkspaceData> $workspaces */
    public function __construct(
        public string $id,
        public string $name,
        public ?OrganizationRole $role,
        public array $workspaces,
    ) {}

    /** @return array{id: string, name: string, role: string|null, workspaces: list<array<string, string>>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role?->value,
            'workspaces' => array_map(fn (WorkspaceData $w): array => $w->toArray(), $this->workspaces),
        ];
    }
}
