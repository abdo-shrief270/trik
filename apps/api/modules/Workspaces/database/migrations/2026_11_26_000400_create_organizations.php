<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ADR 0012: every workspace belongs to an organization.
 *  - workspaces_organizations / workspaces_organization_members (registry tables, no RLS: they
 *    are read to decide what someone may open, like workspaces and workspaces_members)
 *  - workspaces.organization_id required; workspaces.owner_id removed (ownership is the
 *    organization owner's)
 *  - existing data: one organization per previous workspace owner, holding all their workspaces
 *  - every tenant table's RLS is rebuilt as a read policy (workspace list) + write policies (one workspace)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces_organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->timestamps();
        });

        Schema::create('workspaces_organization_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('workspaces_organizations')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20);
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->foreignUuid('organization_id')->nullable()->after('id')->constrained('workspaces_organizations')->restrictOnDelete();
            $table->index('organization_id');
        });

        $this->backfill();

        DB::statement('alter table workspaces alter column organization_id set not null');

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_id');
        });

        // Rebuild every tenant table's policies in the read/write form (partitions included).
        foreach (DB::select("select distinct tablename from pg_policies where schemaname = 'public' and policyname like 'workspace_%'") as $row) {
            RowLevelSecurity::enable((string) $row->tablename);
        }
    }

    private function backfill(): void
    {
        $now = now();
        $orgByOwner = [];

        foreach (DB::table('workspaces')->join('users', 'users.id', '=', 'workspaces.owner_id')->orderBy('workspaces.created_at')->get(['workspaces.id', 'workspaces.owner_id', 'users.name']) as $ws) {
            if (! isset($orgByOwner[$ws->owner_id])) {
                $orgByOwner[$ws->owner_id] = (string) Str::uuid7();
                DB::table('workspaces_organizations')->insert(['id' => $orgByOwner[$ws->owner_id], 'name' => $ws->name, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('workspaces_organization_members')->insert([
                    'id' => (string) Str::uuid7(), 'organization_id' => $orgByOwner[$ws->owner_id], 'user_id' => $ws->owner_id,
                    'role' => 'owner', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            DB::table('workspaces')->where('id', $ws->id)->update(['organization_id' => $orgByOwner[$ws->owner_id]]);
        }

        // everyone already in a workspace appears in its organization's people list
        DB::statement(<<<'SQL'
            insert into workspaces_organization_members (id, organization_id, user_id, role, created_at, updated_at)
            select distinct on (w.organization_id, m.user_id) gen_random_uuid(), w.organization_id, m.user_id, 'member', now(), now()
            from workspaces_members m join workspaces w on w.id = m.workspace_id
            on conflict (organization_id, user_id) do nothing
            SQL);
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            update workspaces w set owner_id = om.user_id
            from workspaces_organization_members om
            where om.organization_id = w.organization_id and om.role = 'owner'
            SQL);
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropConstrainedForeignId('organization_id'));
        Schema::dropIfExists('workspaces_organization_members');
        Schema::dropIfExists('workspaces_organizations');
    }
};
