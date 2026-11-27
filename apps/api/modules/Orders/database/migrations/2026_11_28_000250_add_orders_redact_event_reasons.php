<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The order timeline is append-only: the app role can't update or delete it. Privacy redaction (#333)
 * needs exactly one change, removing the free-text `reason` people type (it may name the customer), so it
 * gets this narrow SECURITY DEFINER function instead of a general UPDATE grant. It runs as the owner,
 * which bypasses row-level security, so it filters on the caller's workspace (app.workspace_id) itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create or replace function orders_redact_event_reasons(p_order_ids uuid[])
            returns integer
            language plpgsql volatile security definer set search_path = pg_catalog, public
            as $$
            declare
                v_workspace uuid := nullif(current_setting('app.workspace_id', true), '')::uuid;
                v_count integer;
            begin
                if v_workspace is null then
                    raise exception 'orders_redact_event_reasons needs a workspace';
                end if;
                update orders_events set data = data - 'reason'
                 where workspace_id = v_workspace and order_id = any (p_order_ids) and jsonb_exists(data, 'reason');
                get diagnostics v_count = row_count;
                return v_count;
            end
            $$
            SQL);
        DB::statement('revoke all on function orders_redact_event_reasons(uuid[]) from public');

        $role = (string) config('database.connections.pgsql.role', 'trik_app');
        if ($role !== '' && preg_match('/^[a-z_][a-z0-9_]*$/', $role) === 1) {
            DB::statement("grant execute on function orders_redact_event_reasons(uuid[]) to \"{$role}\"");
        }
    }

    public function down(): void
    {
        DB::statement('drop function if exists orders_redact_event_reasons(uuid[])');
    }
};
