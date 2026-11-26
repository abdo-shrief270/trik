<?php

declare(strict_types=1);

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_numbers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('external_id');
            $table->string('waba_id')->nullable();
            $table->string('display_phone_number', 32)->nullable();
            $table->string('verified_name')->nullable();
            $table->text('credentials');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['provider', 'external_id']);
            $table->index('workspace_id');
        });
        RowLevelSecurity::enable('whatsapp_numbers');

        Schema::create('whatsapp_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('number_id')->constrained('whatsapp_numbers')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->jsonb('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['workspace_id', 'processed_at']);
        });
        RowLevelSecurity::enable('whatsapp_webhook_events');

        Schema::create('whatsapp_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('number_id')->constrained('whatsapp_numbers')->cascadeOnDelete();
            $table->string('direction', 3);
            $table->string('provider_message_id')->nullable();
            $table->string('contact_phone', 20);
            $table->string('type', 20);
            $table->jsonb('body');
            $table->string('status', 12);
            $table->jsonb('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampsTz();
            $table->index(['workspace_id', 'contact_phone', 'created_at']);
        });
        DB::statement('create unique index whatsapp_messages_provider_id_unique on whatsapp_messages (number_id, provider_message_id) where provider_message_id is not null');
        RowLevelSecurity::enable('whatsapp_messages');

        // Webhook routing before a workspace is active: returns only workspace id + provider.
        $role = (string) config('database.connections.pgsql.role', 'trik_app');

        DB::statement(<<<'SQL'
            create or replace function whatsapp_webhook_route(p_number uuid)
            returns table (workspace_id uuid, provider varchar)
            language sql stable security definer set search_path = public
            as $$ select n.workspace_id, n.provider from whatsapp_numbers n where n.id = p_number $$
            SQL);
        DB::statement(<<<'SQL'
            create or replace function whatsapp_webhook_route_external(p_provider varchar, p_external varchar)
            returns table (workspace_id uuid, number_id uuid)
            language sql stable security definer set search_path = public
            as $$ select n.workspace_id, n.id from whatsapp_numbers n where n.provider = p_provider and n.external_id = p_external $$
            SQL);

        foreach (['whatsapp_webhook_route(uuid)', 'whatsapp_webhook_route_external(varchar, varchar)'] as $fn) {
            DB::statement("revoke all on function {$fn} from public");
            if ($role !== '') {
                DB::statement("grant execute on function {$fn} to \"{$role}\"");
            }
        }
    }

    public function down(): void
    {
        DB::statement('drop function if exists whatsapp_webhook_route_external(varchar, varchar)');
        DB::statement('drop function if exists whatsapp_webhook_route(uuid)');
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_webhook_events');
        Schema::dropIfExists('whatsapp_numbers');
    }
};
