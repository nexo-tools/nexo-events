<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two plain-text fields an organizer can fill in (ADR-012, SPEC-ticket-info):
 *
 *  - access_info: shown only where a ticket is in hand — /t/{token}, the ticket
 *    email and the reminder. The exact address of a venue that does not publish
 *    it, how to get in, what to bring. Never on the public page.
 *  - payment_info: what an entry costs and how it is paid ("Entry $8,000 · pay
 *    at the door"). Display-only: the platform still collects nothing.
 *
 * Additive and nullable: every existing event keeps rendering exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->text('access_info')->nullable()->after('venue');
            $table->string('payment_info', 300)->nullable()->after('access_info');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn(['access_info', 'payment_info']);
        });
    }
};
