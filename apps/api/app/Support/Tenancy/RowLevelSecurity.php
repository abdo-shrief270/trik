<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Migration helper: turns on PostgreSQL row-level security for a tenant table.
 *
 * Use right after Schema::create() for any table with a workspace_id column:
 *     RowLevelSecurity::enable('orders');
 *
 * The application connects as the restricted role (DB_ROLE, default trik_app). It reads rows
 * whose workspace_id is in app.workspace_ids and writes only rows of app.workspace_id, so even
 * the read-only organization context (ADR 0012) can never write across workspaces.
 * Migrations and system jobs use the "owner" connection, which owns the tables and
 * is not subject to the policy.
 */
final class RowLevelSecurity
{
    public static function enable(string $table, string $column = 'workspace_id', ?string $connection = null): void
    {
        self::assertIdentifier($table);
        self::assertIdentifier($column);

        // Writes: only the one active workspace (app.workspace_id).
        $single = sprintf("\"%s\" = nullif(current_setting('%s', true), '')::uuid", $column, TenantContext::SETTING);
        // Reads: the readable list (app.workspace_ids). In a workspace it holds just that workspace;
        // in the read-only organization context it holds the viewer's workspaces in that organization.
        $readable = sprintf(
            "\"%s\" = any(coalesce(string_to_array(nullif(current_setting('%s', true), ''), ',')::uuid[], '{}'::uuid[]))",
            $column,
            TenantContext::READ_SETTING,
        );
        $db = DB::connection($connection);

        $db->statement(sprintf('alter table "%s" enable row level security', $table));

        foreach (['workspace_isolation', 'workspace_read', 'workspace_insert', 'workspace_update', 'workspace_delete'] as $old) {
            $db->statement(sprintf('drop policy if exists %s on "%s"', $old, $table));
        }

        $db->statement(sprintf('create policy workspace_read on "%s" for select using (%s)', $table, $readable));
        $db->statement(sprintf('create policy workspace_insert on "%s" for insert with check (%s)', $table, $single));
        $db->statement(sprintf('create policy workspace_update on "%s" for update using (%s) with check (%s)', $table, $single, $single));
        $db->statement(sprintf('create policy workspace_delete on "%s" for delete using (%s)', $table, $single));
    }

    private static function assertIdentifier(string $name): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid identifier: {$name}");
        }
    }
}
