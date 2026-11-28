<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Privacy webhooks (Shopify's customers/*, shop/redact) arrive after an uninstall too, and concern every
 * workspace that ever held the shop's data: this lookup returns all of a shop's store records, any status.
 * Ids and status only, like commerce_store_route().
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create or replace function commerce_store_history(p_platform varchar, p_shop varchar)
            returns table (workspace_id uuid, store_id uuid, status varchar)
            language sql stable security definer set search_path = pg_catalog, public
            as $$ select s.workspace_id, s.id, s.status from commerce_stores s
                  where s.platform = p_platform and s.shop_domain = p_shop $$
            SQL);
        DB::statement('revoke all on function commerce_store_history(varchar, varchar) from public');

        $role = (string) config('database.connections.pgsql.role', 'trik_app');
        if ($role !== '' && preg_match('/^[a-z_][a-z0-9_]*$/', $role) === 1) {
            DB::statement("grant execute on function commerce_store_history(varchar, varchar) to \"{$role}\"");
        }
    }

    public function down(): void
    {
        DB::statement('drop function if exists commerce_store_history(varchar, varchar)');
    }
};
