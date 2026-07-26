<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit trail for the per-event kill-switch (ADR-007 §4). `status_before_kill`
        // is what makes a kill reversible honestly: restoring without it would have to
        // guess "published", which would silently publish a killed draft.
        Schema::table('events', function (Blueprint $table): void {
            $table->timestamp('killed_at')->nullable()->after('status');
            $table->string('kill_reason', 500)->nullable()->after('killed_at');
            $table->string('status_before_kill', 20)->nullable()->after('kill_reason');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn(['killed_at', 'kill_reason', 'status_before_kill']);
        });
    }
};
