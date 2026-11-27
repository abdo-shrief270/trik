<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public pilot waitlist (trik.chat). Not tenant data: there is no workspace yet, so no RLS.
 * One row per email (stored lower-cased). Consent time and IP are kept as evidence of the opt-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email', 255)->unique();
            $table->string('name', 120);
            $table->string('store_name', 120);
            $table->string('phone', 16);
            $table->string('platform', 20);
            $table->string('monthly_orders', 4);
            $table->string('locale', 2)->default('ar');
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('consent_at');
            $table->string('consent_ip', 45)->nullable();
            $table->timestamp('confirmation_sent_at')->nullable();
            $table->unsignedSmallInteger('confirmations_sent')->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
