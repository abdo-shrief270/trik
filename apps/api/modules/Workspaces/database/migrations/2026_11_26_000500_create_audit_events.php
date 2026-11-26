<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit logs (first part of #87): who changed what in the team, and when.
 *  - workspaces_audit_events: per workspace, tenant data (RLS); workspace admins read it
 *  - workspaces_organization_audit_events: per organization; organization owners/admins read it
 * Append-only: the app role may insert and read, never update or delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces_audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->foreignUuid('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('data')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['workspace_id', 'created_at']);
        });
        RowLevelSecurity::enable('workspaces_audit_events');

        Schema::create('workspaces_organization_audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('workspaces_organizations')->cascadeOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->foreignUuid('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('subject_workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->jsonb('data')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['organization_id', 'created_at']);
        });

        $role = (string) config('database.connections.pgsql.role', 'trik_app');

        if ($role !== '' && preg_match('/^[a-z_][a-z0-9_]*$/', $role) === 1) {
            foreach (['workspaces_audit_events', 'workspaces_organization_audit_events'] as $table) {
                DB::statement(sprintf('revoke update, delete on table "%s" from "%s"', $table, $role));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces_organization_audit_events');
        Schema::dropIfExists('workspaces_audit_events');
    }
};
