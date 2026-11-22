<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional "More information" link per event (PLAN 10.15, SPEC-ticket-info),
 * so an integration stops pasting an untappable URL into the description. Public
 * like the description, but only on /e/{slug}: never on the ticket, the emails,
 * the JSON-LD or the sitemap. https only, validated in EventRequest.
 *
 * 2048 is the practical ceiling browsers and proxies agree on for a URL.
 * Nullable and additive: every existing event keeps rendering as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('info_url', 2048)->nullable()->after('payment_info');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('info_url');
        });
    }
};
