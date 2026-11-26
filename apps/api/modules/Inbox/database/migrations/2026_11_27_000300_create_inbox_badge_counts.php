<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Badge counters for the workspace switcher (#368), maintained by statement-level triggers on
 * inbox_conversations: whatever writes a conversation (the webhook batch upsert, an assignment, a
 * close), the counters move in the same transaction, with one counter statement per write statement
 * (transition tables), not one per row. `inbox:recount-badges` rebuilds them nightly as a safety net.
 *
 * Rows: user_id = the zero uuid is the whole workspace (open conversations with unread messages);
 * user_id = a person holds their open assigned conversations, and how many of those are unread.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table inbox_badge_counts (
                workspace_id uuid not null references workspaces (id) on delete cascade,
                user_id uuid not null,
                unread integer not null default 0,
                assigned integer not null default 0,
                primary key (workspace_id, user_id)
            )
            SQL);
        RowLevelSecurity::enable('inbox_badge_counts');

        // What one conversation row contributes to the counters, for a transition table.
        $contrib = fn (string $rows, string $sign): string => <<<SQL
            select workspace_id, '00000000-0000-0000-0000-000000000000'::uuid as user_id,
                   {$sign}(status = 'open' and unread_count > 0)::int as d_unread, 0 as d_assigned
            from {$rows}
            union all
            select workspace_id, assignee_id, {$sign}(status = 'open' and unread_count > 0)::int, {$sign}(status = 'open')::int
            from {$rows} where assignee_id is not null
            SQL;

        $apply = fn (string $deltas): string => <<<SQL
            insert into inbox_badge_counts as b (workspace_id, user_id, unread, assigned)
            select workspace_id, user_id, sum(d_unread), sum(d_assigned)
            from ({$deltas}) as d
            group by workspace_id, user_id
            having sum(d_unread) <> 0 or sum(d_assigned) <> 0
            order by workspace_id, user_id
            on conflict (workspace_id, user_id) do update
                set unread = b.unread + excluded.unread, assigned = b.assigned + excluded.assigned;
            SQL;

        $bodies = [
            'insert' => $apply($contrib('new_rows', '+')),
            'update' => $apply($contrib('new_rows', '+')."\nunion all\n".$contrib('old_rows', '-')),
            'delete' => $apply($contrib('old_rows', '-')),
        ];

        foreach ($bodies as $op => $body) {
            DB::statement(<<<SQL
                create or replace function inbox_badges_on_{$op}() returns trigger language plpgsql
                set search_path = pg_catalog, public as \$\$
                begin
                    {$body}
                    return null;
                end
                \$\$
                SQL);
        }

        DB::statement('create trigger inbox_badges_insert after insert on inbox_conversations referencing new table as new_rows for each statement execute function inbox_badges_on_insert()');
        DB::statement('create trigger inbox_badges_update after update on inbox_conversations referencing old table as old_rows new table as new_rows for each statement execute function inbox_badges_on_update()');
        DB::statement('create trigger inbox_badges_delete after delete on inbox_conversations referencing old table as old_rows for each statement execute function inbox_badges_on_delete()');
    }

    public function down(): void
    {
        foreach (['insert', 'update', 'delete'] as $op) {
            DB::statement("drop trigger if exists inbox_badges_{$op} on inbox_conversations");
            DB::statement("drop function if exists inbox_badges_on_{$op}()");
        }
        Schema::dropIfExists('inbox_badge_counts');
    }
};
