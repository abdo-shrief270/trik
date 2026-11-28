<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** What Trik writes back to the store (Store::SETTINGS for the defaults). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("alter table commerce_stores add column settings jsonb not null default '{}'::jsonb");
    }

    public function down(): void
    {
        DB::statement('alter table commerce_stores drop column if exists settings');
    }
};
