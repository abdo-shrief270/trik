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
 * The application connects as the restricted role (DB_ROLE, default trik_app), which
 * only sees rows whose workspace_id equals the session setting app.workspace_id.
 * Migrations and system jobs use the "owner" connection, which owns the tables and
 * is not subject to the policy.
 */
final class RowLevelSecurity
{
    public static function enable(string $table, string $column = 'workspace_id', ?string $connection = null): void
    {
        self::assertIdentifier($table);
        self::assertIdentifier($column);

        $match = sprintf("\"%s\" = nullif(current_setting('%s', true), '')::uuid", $column, TenantContext::SETTING);
        $db = DB::connection($connection);

        $db->statement(sprintf('alter table "%s" enable row level security', $table));
        $db->statement(sprintf('drop policy if exists workspace_isolation on "%s"', $table));
        $db->statement(sprintf('create policy workspace_isolation on "%s" using (%s) with check (%s)', $table, $match, $match));
    }

    private static function assertIdentifier(string $name): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid identifier: {$name}");
        }
    }
}
