<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Team invitations (#36). A tenant table (it holds the invitee's email), so it has RLS; the link's token is found
 * before the workspace is known through one narrow SECURITY DEFINER function that returns only ids. Only the
 * token's SHA-256 is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('role', 20);
            // deleting a custom role withdraws the invitations that offered it
            $table->foreignUuid('custom_role_id')->nullable()->constrained('workspaces_roles')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUuid('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });

        // one open invitation per person per workspace (inviting again replaces it)
        DB::statement(<<<'SQL'
            create unique index workspaces_invitations_open_unique on workspaces_invitations (workspace_id, email)
            where accepted_at is null and revoked_at is null
            SQL);

        RowLevelSecurity::enable('workspaces_invitations');

        DB::statement(<<<'SQL'
            create or replace function workspaces_invitation_by_token(p_hash char)
            returns table (workspace_id uuid, invitation_id uuid)
            language sql stable security definer set search_path = pg_catalog, public
            as $$ select i.workspace_id, i.id from workspaces_invitations i where i.token_hash = p_hash $$
            SQL);
        DB::statement('revoke all on function workspaces_invitation_by_token(char) from public');

        $role = (string) config('database.connections.pgsql.role', 'trik_app');
        if ($role !== '' && preg_match('/^[a-z_][a-z0-9_]*$/', $role) === 1) {
            DB::statement("grant execute on function workspaces_invitation_by_token(char) to \"{$role}\"");
        }
    }

    public function down(): void
    {
        DB::statement('drop function if exists workspaces_invitation_by_token(char)');
        Schema::dropIfExists('workspaces_invitations');
    }
};
