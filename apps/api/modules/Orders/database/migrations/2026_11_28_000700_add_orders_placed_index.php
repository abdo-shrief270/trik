<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Date-range reports per workspace (pilot results, #345) scan orders by placed_at. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index if not exists orders_orders_placed_idx on orders_orders (workspace_id, placed_at)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists orders_orders_placed_idx');
    }
};
