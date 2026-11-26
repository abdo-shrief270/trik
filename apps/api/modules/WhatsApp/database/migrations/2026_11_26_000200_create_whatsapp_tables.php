<?php

declare(strict_types=1);

use App\Support\Database\MonthlyPartitions;
use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp storage, shaped for the scale path in ADR 0010:
 *  - whatsapp_numbers            small, regular table
 *  - whatsapp_webhook_events     raw deliveries, monthly partitions, dropped after retention
 *  - whatsapp_messages           conversation messages (inbox), monthly partitions
 *  - whatsapp_broadcast_sends    campaign sends, kept apart from conversations, monthly partitions
 *  - whatsapp_inbound_keys       de-duplication of inbound provider ids (partitioned tables
 *                                can't hold a unique key without the partition column)
 * Partitioned tables use (id, created_at) as primary key; ids are UUIDv7 (time-ordered).
 */
return new class extends Migration
{
    private const PARTITIONED = ['whatsapp_webhook_events', 'whatsapp_messages', 'whatsapp_broadcast_sends'];

    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table whatsapp_numbers (
                id uuid primary key,
                workspace_id uuid not null references workspaces (id) on delete cascade,
                provider varchar(20) not null,
                external_id varchar(255) not null,
                waba_id varchar(255),
                display_phone_number varchar(32),
                verified_name varchar(255),
                credentials text not null,
                status varchar(20) not null default 'active',
                created_at timestamptz,
                updated_at timestamptz,
                unique (provider, external_id)
            )
            SQL);
        DB::statement('create index whatsapp_numbers_workspace_idx on whatsapp_numbers (workspace_id)');
        RowLevelSecurity::enable('whatsapp_numbers');

        DB::statement(<<<'SQL'
            create table whatsapp_webhook_events (
                id uuid not null,
                workspace_id uuid not null references workspaces (id) on delete cascade,
                number_id uuid not null references whatsapp_numbers (id) on delete cascade,
                provider varchar(20) not null,
                payload jsonb not null,
                attempts smallint not null default 0,
                last_error text,
                processed_at timestamptz,
                created_at timestamptz not null default now(),
                primary key (id, created_at)
            ) partition by range (created_at)
            SQL);
        DB::statement('create index whatsapp_webhook_events_pending_idx on whatsapp_webhook_events (workspace_id, created_at) where processed_at is null');

        DB::statement(<<<'SQL'
            create table whatsapp_messages (
                id uuid not null,
                workspace_id uuid not null references workspaces (id) on delete cascade,
                number_id uuid not null references whatsapp_numbers (id) on delete cascade,
                direction varchar(3) not null,
                provider_message_id varchar(255),
                contact_phone varchar(20) not null,
                type varchar(20) not null,
                body jsonb not null,
                status varchar(12) not null,
                error jsonb,
                attempts smallint not null default 0,
                sent_at timestamptz,
                delivered_at timestamptz,
                read_at timestamptz,
                failed_at timestamptz,
                received_at timestamptz,
                created_at timestamptz not null default now(),
                updated_at timestamptz,
                primary key (id, created_at)
            ) partition by range (created_at)
            SQL);
        DB::statement('create index whatsapp_messages_provider_idx on whatsapp_messages (number_id, provider_message_id) where provider_message_id is not null');
        DB::statement('create index whatsapp_messages_contact_idx on whatsapp_messages (workspace_id, contact_phone, created_at)');
        // the message log, newest first (2M rows: 2.4 s without it, see docs/security/2026-11-review.md)
        DB::statement('create index whatsapp_messages_number_created_idx on whatsapp_messages (number_id, created_at desc, id desc)');

        DB::statement(<<<'SQL'
            create table whatsapp_broadcast_sends (
                id uuid not null,
                workspace_id uuid not null references workspaces (id) on delete cascade,
                number_id uuid not null references whatsapp_numbers (id) on delete cascade,
                broadcast_id uuid not null,
                contact_phone varchar(20) not null,
                template varchar(512) not null,
                provider_message_id varchar(255),
                status varchar(12) not null,
                error jsonb,
                attempts smallint not null default 0,
                sent_at timestamptz,
                delivered_at timestamptz,
                read_at timestamptz,
                failed_at timestamptz,
                created_at timestamptz not null default now(),
                updated_at timestamptz,
                primary key (id, created_at)
            ) partition by range (created_at)
            SQL);
        DB::statement('create index whatsapp_broadcast_sends_provider_idx on whatsapp_broadcast_sends (number_id, provider_message_id) where provider_message_id is not null');
        DB::statement('create index whatsapp_broadcast_sends_queue_idx on whatsapp_broadcast_sends (broadcast_id, status)');

        foreach (self::PARTITIONED as $table) {
            RowLevelSecurity::enable($table);
            MonthlyPartitions::ensure($table, now(), monthsAhead: 3, fillfactor: MonthlyPartitions::fillfactorFor($table));
        }

        DB::statement(<<<'SQL'
            create table whatsapp_inbound_keys (
                number_id uuid not null references whatsapp_numbers (id) on delete cascade,
                provider_message_id varchar(255) not null,
                workspace_id uuid not null references workspaces (id) on delete cascade,
                created_at timestamptz not null default now(),
                primary key (number_id, provider_message_id)
            )
            SQL);
        DB::statement('create index whatsapp_inbound_keys_created_idx on whatsapp_inbound_keys (created_at)');
        RowLevelSecurity::enable('whatsapp_inbound_keys');

        // Webhook routing before a workspace is active: returns only workspace id + provider.
        $role = (string) config('database.connections.pgsql.role', 'trik_app');

        DB::statement(<<<'SQL'
            create or replace function whatsapp_webhook_route(p_number uuid)
            returns table (workspace_id uuid, provider varchar)
            language sql stable security definer set search_path = pg_catalog, public
            as $$ select n.workspace_id, n.provider from whatsapp_numbers n where n.id = p_number $$
            SQL);
        DB::statement(<<<'SQL'
            create or replace function whatsapp_webhook_route_external(p_provider varchar, p_external varchar)
            returns table (workspace_id uuid, number_id uuid)
            language sql stable security definer set search_path = pg_catalog, public
            as $$ select n.workspace_id, n.id from whatsapp_numbers n where n.provider = p_provider and n.external_id = p_external $$
            SQL);

        foreach (['whatsapp_webhook_route(uuid)', 'whatsapp_webhook_route_external(varchar, varchar)'] as $fn) {
            DB::statement("revoke all on function {$fn} from public");
            if ($role !== '') {
                DB::statement("grant execute on function {$fn} to \"{$role}\"");
            }
        }
    }

    public function down(): void
    {
        DB::statement('drop function if exists whatsapp_webhook_route_external(varchar, varchar)');
        DB::statement('drop function if exists whatsapp_webhook_route(uuid)');

        foreach (['whatsapp_inbound_keys', ...array_reverse(self::PARTITIONED), 'whatsapp_numbers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
