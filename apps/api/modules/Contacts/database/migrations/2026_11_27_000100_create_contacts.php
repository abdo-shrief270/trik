<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customers of a workspace. One row per person, found by their WhatsApp number (digits, wa_id
 * format). Other channels (Instagram, Messenger, #81 #82) will add an identities table that points
 * here, so every channel of one customer still resolves to the same contact and conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table contacts_contacts (
                id uuid primary key,
                workspace_id uuid not null references workspaces (id) on delete cascade,
                phone varchar(20) not null,
                name varchar(255),
                created_at timestamptz,
                updated_at timestamptz,
                unique (workspace_id, phone)
            )
            SQL);
        RowLevelSecurity::enable('contacts_contacts');
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts_contacts');
    }
};
