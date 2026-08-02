<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language the attendee registered in, kept with the ticket.
 *
 * Mail is queued, and by the time the worker picks the job up there is no
 * request to read a locale from: whatever the app default is wins. That is fine
 * for the ticket itself (it is dispatched with the locale pinned at
 * registration time) but not for anything sent later — a cancellation notice
 * goes out days afterwards, triggered by the organizer, in the organizer's
 * session, to attendees who may have registered in another language.
 *
 * Nullable and additive: tickets issued before this migration have no locale
 * and fall back to the app default, which is exactly the behaviour they had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('attendee_email');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
