<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * EasyOrders (#117) sends webhooks with only a shared `secret` header, and the install flow doesn't tell us
 * that secret. So each store's webhook URL carries its own random token; only its SHA-256 is stored, and
 * commerce_store_by_hook() finds the store from it before any workspace is active (active stores only,
 * ids only). `webhook_secret` (encrypted) holds the header value when a seller set the webhook up by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table commerce_stores add column webhook_token_hash char(64), add column webhook_secret text');
        DB::statement('create unique index commerce_stores_webhook_token_idx on commerce_stores (webhook_token_hash) where webhook_token_hash is not null');

        DB::statement(<<<'SQL'
            create or replace function commerce_store_by_hook(p_hash varchar)
            returns table (workspace_id uuid, store_id uuid)
            language sql stable security definer set search_path = pg_catalog, public
            as $$ select s.workspace_id, s.id from commerce_stores s where s.webhook_token_hash = p_hash and s.status = 'active' $$
            SQL);
        DB::statement('revoke all on function commerce_store_by_hook(varchar) from public');

        $role = (string) config('database.connections.pgsql.role', 'trik_app');
        if ($role !== '' && preg_match('/^[a-z_][a-z0-9_]*$/', $role) === 1) {
            DB::statement("grant execute on function commerce_store_by_hook(varchar) to \"{$role}\"");
        }
    }

    public function down(): void
    {
        DB::statement('drop function if exists commerce_store_by_hook(varchar)');
        DB::statement('drop index if exists commerce_stores_webhook_token_idx');
        DB::statement('alter table commerce_stores drop column if exists webhook_token_hash, drop column if exists webhook_secret');
    }
};
