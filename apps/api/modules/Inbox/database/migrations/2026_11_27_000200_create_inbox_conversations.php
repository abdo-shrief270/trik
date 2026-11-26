<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One conversation per customer per workspace (#56), whatever number or channel they write to.
 * Messages stay in their channel's log (whatsapp_messages); this row is the summary the inbox
 * lists and sorts: last message, unread count, assignee, status and the messaging window (#60).
 * It is updated set-based in the same transaction as the messages, so it never drifts.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table inbox_conversations (
                id uuid primary key,
                workspace_id uuid not null references workspaces (id) on delete cascade,
                contact_id uuid not null references contacts_contacts (id) on delete cascade,
                status varchar(10) not null default 'open' check (status in ('open', 'closed')),
                assignee_id uuid references users (id) on delete set null,
                number_id uuid references whatsapp_numbers (id) on delete set null,
                unread_count integer not null default 0 check (unread_count >= 0),
                last_message_at timestamptz not null,
                last_message_direction varchar(3) not null,
                last_message_type varchar(20) not null,
                last_message_preview varchar(200),
                last_inbound_at timestamptz,
                ad_referral_at timestamptz,
                free_until timestamptz,
                closed_at timestamptz,
                created_at timestamptz,
                updated_at timestamptz,
                unique (workspace_id, contact_id)
            )
            SQL);
        // The inbox list: newest first, per status, and per assignee ("mine", "unassigned").
        DB::statement('create index inbox_conversations_list_idx on inbox_conversations (workspace_id, status, last_message_at desc, id desc)');
        DB::statement('create index inbox_conversations_assignee_idx on inbox_conversations (workspace_id, assignee_id, status, last_message_at desc, id desc)');
        RowLevelSecurity::enable('inbox_conversations');
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_conversations');
    }
};
