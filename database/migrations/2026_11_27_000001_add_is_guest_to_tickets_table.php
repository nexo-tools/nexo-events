<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Invitado» (SPEC-event-panel AC-PANEL-11, 2026-11-27): a ticket the organizer
 * marks as a guest from the registrations list does not count against the
 * event's capacity. Additive, default false: every ticket issued before this
 * keeps counting, as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->boolean('is_guest')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropColumn('is_guest');
        });
    }
};
