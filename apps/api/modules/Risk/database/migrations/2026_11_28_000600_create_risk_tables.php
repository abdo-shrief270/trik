<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refusal risk (#115):
 *  - risk_settings     per workspace: the threshold above which orders are flagged, and weight overrides;
 *  - risk_assessments  per order: the score, every reason with its points, the inputs it read and the
 *                      model version, so a score can always be explained later.
 * The score itself is also on orders_orders.risk_score (the board filters on it).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table risk_settings (
                workspace_id uuid primary key references workspaces (id) on delete cascade,
                threshold smallint not null default 70 check (threshold between 1 and 100),
                weights jsonb not null default '{}'::jsonb,
                updated_at timestamptz
            )
            SQL);
        RowLevelSecurity::enable('risk_settings');

        DB::statement(<<<'SQL'
            create table risk_assessments (
                order_id uuid primary key references orders_orders (id) on delete cascade,
                workspace_id uuid not null references workspaces (id) on delete cascade,
                score smallint not null check (score between 0 and 100),
                reasons jsonb not null,
                inputs jsonb not null,
                model varchar(20) not null,
                flagged_at timestamptz,
                computed_at timestamptz not null default now()
            )
            SQL);
        DB::statement('create index risk_assessments_workspace_idx on risk_assessments (workspace_id, score desc)');
        RowLevelSecurity::enable('risk_assessments');
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
        Schema::dropIfExists('risk_settings');
    }
};
