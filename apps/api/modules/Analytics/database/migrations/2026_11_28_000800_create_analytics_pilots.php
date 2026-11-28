<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pilot results (#345): per workspace, when Trik went live (null: the first WhatsApp confirmation counts)
 * and how many days before it make the baseline.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table analytics_pilots (
                workspace_id uuid primary key references workspaces (id) on delete cascade,
                live_at timestamptz,
                baseline_days smallint not null default 90 check (baseline_days between 14 and 180),
                updated_at timestamptz
            )
            SQL);
        RowLevelSecurity::enable('analytics_pilots');
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_pilots');
    }
};
