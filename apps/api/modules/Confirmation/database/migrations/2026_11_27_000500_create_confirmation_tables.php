<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The confirmation engine (#106):
 *  - confirmation_settings  per workspace: the template, delays, replies (off until a template is set)
 *  - confirmation_runs      one per order: where it stands and when to act next. The minute scheduler
 *                           picks due runs with a partial index, so it stays cheap with millions of orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table confirmation_settings (
                workspace_id uuid primary key references workspaces (id) on delete cascade,
                enabled boolean not null default false,
                number_id uuid references whatsapp_numbers (id) on delete set null,
                template_name varchar(512),
                template_language varchar(16) not null default 'ar',
                reminder_template_name varchar(512),
                reminder_after_minutes integer not null default 120 check (reminder_after_minutes between 5 and 10080),
                max_reminders smallint not null default 1 check (max_reminders between 0 and 5),
                escalate_after_minutes integer not null default 120 check (escalate_after_minutes between 5 and 10080),
                confirmed_reply text,
                cancelled_reply text,
                change_reply text,
                updated_at timestamptz
            )
            SQL);
        RowLevelSecurity::enable('confirmation_settings');

        DB::statement(<<<'SQL'
            create table confirmation_runs (
                id uuid primary key,
                workspace_id uuid not null references workspaces (id) on delete cascade,
                order_id uuid not null unique references orders_orders (id) on delete cascade,
                status varchar(20) not null,
                number_id uuid references whatsapp_numbers (id) on delete set null,
                messages_sent smallint not null default 0,
                reminders_sent smallint not null default 0,
                last_message_id uuid,
                next_action_at timestamptz,
                resolved_at timestamptz,
                outcome varchar(20),
                created_at timestamptz,
                updated_at timestamptz
            )
            SQL);
        DB::statement("create index confirmation_runs_due_idx on confirmation_runs (next_action_at) where status in ('pending', 'sent')");
        DB::statement('create index confirmation_runs_workspace_idx on confirmation_runs (workspace_id, status, created_at desc)');
        RowLevelSecurity::enable('confirmation_runs');
    }

    public function down(): void
    {
        Schema::dropIfExists('confirmation_runs');
        Schema::dropIfExists('confirmation_settings');
    }
};
