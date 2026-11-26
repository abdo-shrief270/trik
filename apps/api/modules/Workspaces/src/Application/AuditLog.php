<?php

declare(strict_types=1);

namespace Modules\Workspaces\Application;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/** Writes team events to the workspace and/or organization audit logs. Append-only. */
final class AuditLog
{
    public function __construct(private readonly TenantContext $context) {}

    /** @param array<string, mixed> $data */
    public function workspace(string $workspaceId, string $action, ?string $actorId, ?string $subjectUserId = null, array $data = []): void
    {
        // tenant data: written inside that workspace, whatever context the caller is in
        $this->context->run($workspaceId, fn () => DB::table('workspaces_audit_events')->insert([
            'id' => (string) Str::uuid7(),
            'workspace_id' => $workspaceId,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_user_id' => $subjectUserId,
            'data' => $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'ip' => Request::ip(),
            'created_at' => now(),
        ]));
    }

    /** @param array<string, mixed> $data */
    public function organization(string $organizationId, string $action, ?string $actorId, ?string $subjectUserId = null, ?string $workspaceId = null, array $data = []): void
    {
        DB::table('workspaces_organization_audit_events')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $organizationId,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_user_id' => $subjectUserId,
            'subject_workspace_id' => $workspaceId,
            'data' => $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'ip' => Request::ip(),
            'created_at' => now(),
        ]);
    }
}
