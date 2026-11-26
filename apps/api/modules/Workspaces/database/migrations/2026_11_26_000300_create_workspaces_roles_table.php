<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Custom roles per workspace. Built-in roles (owner, admin, agent) live in code. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('description', 255)->nullable();
            $table->jsonb('permissions');
            $table->timestamps();
            $table->unique(['workspace_id', 'name']);
        });
        RowLevelSecurity::enable('workspaces_roles');

        Schema::table('workspaces_members', function (Blueprint $table): void {
            $table->foreignUuid('custom_role_id')->nullable()->after('role')->constrained('workspaces_roles')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces_members', fn (Blueprint $table) => $table->dropConstrainedForeignId('custom_role_id'));
        Schema::dropIfExists('workspaces_roles');
    }
};
