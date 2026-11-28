<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Privacy requests (#333 first: Shopify's customers/data_request, customers/redact, shop/redact).
 * One row per request per workspace, unique per (origin, delivery) so a redelivered webhook is a no-op.
 * The subject (phones, order ids) and the data export are personal data: stored encrypted, and the
 * export is dropped after 30 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table compliance_requests (
                id uuid primary key,
                workspace_id uuid not null references workspaces (id) on delete cascade,
                kind varchar(30) not null,
                origin varchar(20) not null,
                origin_ref varchar(255),
                delivery_id varchar(128) not null,
                subject text not null,
                status varchar(20) not null default 'pending',
                result jsonb not null default '{}'::jsonb,
                export text,
                export_expires_at timestamptz,
                attempts smallint not null default 0,
                error text,
                created_at timestamptz not null default now(),
                completed_at timestamptz,
                unique (workspace_id, origin, delivery_id)
            )
            SQL);
        DB::statement('create index compliance_requests_workspace_idx on compliance_requests (workspace_id, created_at desc)');
        DB::statement('create index compliance_requests_export_idx on compliance_requests (export_expires_at) where export is not null');
        RowLevelSecurity::enable('compliance_requests');
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_requests');
    }
};
