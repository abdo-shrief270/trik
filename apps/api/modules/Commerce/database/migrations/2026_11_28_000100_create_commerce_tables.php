<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store connectors (#332 Shopify, then #117 EasyOrders):
 *  - commerce_stores          a connected shop and its (encrypted) access token, one active connection per shop
 *  - commerce_webhook_events  raw store deliveries, de-duplicated by the platform's delivery id, drained on the queue
 *  - commerce_order_versions  the store's last applied updated_at per order, so a late or replayed
 *                             delivery (or the backfill) never overwrites a newer version
 * Plus commerce_store_route(), a narrow SECURITY DEFINER lookup used by webhooks and installs before
 * any workspace is active (the app role never reads stores across workspaces).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table commerce_stores (
                id uuid primary key,
                workspace_id uuid not null references workspaces (id) on delete cascade,
                platform varchar(20) not null,
                shop_domain varchar(255) not null,
                name varchar(255),
                access_token text,
                scopes text not null default '',
                status varchar(20) not null default 'active',
                installed_by uuid,
                installed_at timestamptz,
                uninstalled_at timestamptz,
                backfill jsonb not null default '{}'::jsonb,
                last_webhook_at timestamptz,
                created_at timestamptz,
                updated_at timestamptz,
                unique (workspace_id, platform, shop_domain)
            )
            SQL);
        // A shop feeds one workspace at a time: its webhooks must route to exactly one place.
        DB::statement("create unique index commerce_stores_active_shop_idx on commerce_stores (platform, shop_domain) where status = 'active'");
        RowLevelSecurity::enable('commerce_stores');

        DB::statement(<<<'SQL'
            create table commerce_webhook_events (
                id uuid primary key,
                workspace_id uuid not null references workspaces (id) on delete cascade,
                store_id uuid not null references commerce_stores (id) on delete cascade,
                topic varchar(64) not null,
                delivery_id varchar(128) not null,
                payload jsonb not null,
                attempts smallint not null default 0,
                last_error text,
                outcome varchar(40),
                processed_at timestamptz,
                created_at timestamptz not null default now(),
                unique (store_id, delivery_id)
            )
            SQL);
        DB::statement('create index commerce_webhook_events_pending_idx on commerce_webhook_events (workspace_id, created_at) where processed_at is null');
        DB::statement('create index commerce_webhook_events_processed_idx on commerce_webhook_events (processed_at) where processed_at is not null');
        RowLevelSecurity::enable('commerce_webhook_events');

        DB::statement(<<<'SQL'
            create table commerce_order_versions (
                workspace_id uuid not null references workspaces (id) on delete cascade,
                store_id uuid not null references commerce_stores (id) on delete cascade,
                external_id varchar(64) not null,
                source_updated_at timestamptz not null,
                primary key (store_id, external_id)
            )
            SQL);
        RowLevelSecurity::enable('commerce_order_versions');

        DB::statement(<<<'SQL'
            create or replace function commerce_store_route(p_platform varchar, p_shop varchar)
            returns table (workspace_id uuid, store_id uuid)
            language sql stable security definer set search_path = pg_catalog, public
            as $$ select s.workspace_id, s.id from commerce_stores s
                  where s.platform = p_platform and s.shop_domain = p_shop and s.status = 'active' $$
            SQL);
        DB::statement('revoke all on function commerce_store_route(varchar, varchar) from public');

        $role = (string) config('database.connections.pgsql.role', 'trik_app');
        if ($role !== '' && preg_match('/^[a-z_][a-z0-9_]*$/', $role) === 1) {
            DB::statement("grant execute on function commerce_store_route(varchar, varchar) to \"{$role}\"");
        }
    }

    public function down(): void
    {
        DB::statement('drop function if exists commerce_store_route(varchar, varchar)');
        Schema::dropIfExists('commerce_order_versions');
        Schema::dropIfExists('commerce_webhook_events');
        Schema::dropIfExists('commerce_stores');
    }
};
