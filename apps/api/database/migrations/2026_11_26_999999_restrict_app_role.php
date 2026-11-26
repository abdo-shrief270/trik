<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Runs last. The app role keeps data access to app tables only: it never touches the
 * migrations table (which would let an attacker who gets SQL access fake the schema state).
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = (string) config('database.connections.pgsql.role', 'trik_app');

        if ($role === '' || DB::getDriverName() !== 'pgsql' || preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            return;
        }

        $table = (string) config('database.migrations.table', 'migrations');
        DB::statement(sprintf('revoke all on table "%s" from "%s"', $table, $role));
    }
};
