<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the pre-event reminder went out for this ticket.
 *
 * It is the idempotency key of the reminder command: the scheduler runs every
 * hour, and a person must get exactly one reminder no matter how many times the
 * window is scanned. Storing it on the ticket (rather than on the event) also
 * covers the case that actually happens — somebody registering the same morning
 * of the event, after the first sweep already ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
