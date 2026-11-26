<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The confirmation board (#107):
 *  - owner_id        the person responsible for the order
 *  - needs_call_at   set when confirmation needs a person (no reply, change requested)
 *  - risk_score      0–100, filled by the refusal risk score (#115); null until then
 *  - stage           the board column, computed by PostgreSQL from status + needs_call_at, so it can
 *                    never drift, and indexed so every column and its count is one index range
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table orders_orders
            add column owner_id uuid references users (id) on delete set null,
            add column needs_call_at timestamptz,
            add column needs_call_reason varchar(40),
            add column risk_score smallint check (risk_score between 0 and 100)');

        DB::statement(<<<'SQL'
            alter table orders_orders add column stage varchar(20) generated always as (
                case
                    when status = 'cancelled' then 'cancelled'
                    when status in ('confirmed', 'ready_to_ship', 'shipped', 'delivered', 'returned') then 'confirmed'
                    when needs_call_at is not null then 'needs_call'
                    when status = 'new' then 'new'
                    else 'awaiting_customer'
                end
            ) stored
            SQL);

        DB::statement('create index orders_orders_board_idx on orders_orders (workspace_id, stage, placed_at desc, id desc)');
        DB::statement('create index orders_orders_owner_idx on orders_orders (workspace_id, owner_id, stage)');
        DB::statement("create index orders_orders_governorate_idx on orders_orders (workspace_id, (address->>'governorate'))");
    }

    public function down(): void
    {
        DB::statement('alter table orders_orders drop column if exists stage, drop column if exists risk_score,
            drop column if exists needs_call_reason, drop column if exists needs_call_at, drop column if exists owner_id');
    }
};
