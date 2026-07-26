<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cookieless page-view counter (ADR-007 §6). One row per visitor-day, so
        // the organizer sees unique visitors rather than reloads. `visitor_hash`
        // is a daily-rotating digest, not an identifier: it cannot be reversed to
        // an IP and cannot be joined across days.
        Schema::create('event_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->char('visitor_hash', 64);
            $table->date('viewed_on');
            $table->timestamp('created_at')->nullable();

            // The dedupe IS the constraint: a second view the same day is a
            // no-op insert rather than an application-level check with a race.
            $table->unique(['event_id', 'visitor_hash', 'viewed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_views');
    }
};
