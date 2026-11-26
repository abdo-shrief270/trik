<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the restricted role the app runs as (DB_ROLE, default trik_app) and gives it
 * data access to every table created from now on. Runs first, on the owner connection.
 * Row-level security policies apply to this role; the owner bypasses them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = (string) config('database.connections.pgsql.role', 'trik_app');

        if ($role === '' || DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (preg_match('/^[a-z_][a-z0-9_]*$/', $role) !== 1) {
            throw new InvalidArgumentException('DB_ROLE must be a plain identifier.');
        }

        DB::statement(<<<SQL
            do $$ begin
              if not exists (select 1 from pg_roles where rolname = '{$role}') then
                create role "{$role}" nologin nosuperuser nobypassrls;
              end if;
            end $$
            SQL);

        DB::statement("grant \"{$role}\" to current_user");
        DB::statement("grant usage on schema public to \"{$role}\"");
        DB::statement("alter default privileges in schema public grant select, insert, update, delete on tables to \"{$role}\"");
        DB::statement("alter default privileges in schema public grant usage, select on sequences to \"{$role}\"");
    }
};
