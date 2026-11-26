<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Who on the team sent an outbound message (null for automations and inbound messages). */
return new class extends Migration
{
    public function up(): void
    {
        // Nullable, no default: a metadata-only change, even on a large partitioned table.
        DB::statement('alter table whatsapp_messages add column sent_by uuid');
    }

    public function down(): void
    {
        DB::statement('alter table whatsapp_messages drop column if exists sent_by');
    }
};
