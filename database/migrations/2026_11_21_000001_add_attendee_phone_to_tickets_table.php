<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The optional WhatsApp/phone an attendee may leave when registering (PLAN
 * 10.13, ADR-003 amendment 2026-11-21). Only collected while the instance has
 * NEXO_ATTENDEE_PHONE=optional, and only ever shown to the event owner.
 *
 * Nullable and additive: every ticket issued before this, and every ticket of
 * an instance that keeps the switch off, simply has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->string('attendee_phone', 30)->nullable()->after('attendee_email');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropColumn('attendee_phone');
        });
    }
};
