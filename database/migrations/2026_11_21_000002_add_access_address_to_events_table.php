<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The venue's exact address on its own (PLAN 10.14, SPEC-ticket-info), so the
 * ticket can offer "copy" and "open in a map" instead of burying it inside the
 * free text of access_info. Private exactly like access_info (ADR-012): the
 * ticket, its emails and the organizer's screens, never a public surface.
 *
 * Nullable and additive: every existing event keeps rendering as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('access_address', 255)->nullable()->after('venue');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('access_address');
        });
    }
};
