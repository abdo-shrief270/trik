<?php

declare(strict_types=1);

namespace Modules\Workspaces\Application;

use App\Support\Http\Problem;
use App\Support\Tenancy\TenantContext;

/**
 * Read-only view across the viewer's own workspaces in one organization (ADR 0012, §3).
 * Used by organization reports. Writes are impossible inside it (Eloquent and RLS refuse them).
 */
final class OrganizationContext
{
    public function __construct(
        private readonly WorkspaceDirectory $directory,
        private readonly TenantContext $context,
    ) {}

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(string $organizationId, string $userId, callable $callback): mixed
    {
        $ids = $this->directory->workspaceIdsIn($organizationId, $userId);

        if ($ids === []) {
            throw Problem::forbidden('not-in-organization', 'You are not a member of any workspace in this organization.');
        }

        return $this->context->runReadOnly($ids, $callback);
    }
}
