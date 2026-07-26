<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Abuse reports on public events (ADR-007 §3). No login required to
        // report, so there is deliberately no reporter user_id — only an
        // optional email the operator can follow up on.
        Schema::create('event_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 1000);
            $table->string('reporter_email')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_reports');
    }
};
