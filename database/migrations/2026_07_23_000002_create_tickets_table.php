<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('attendee_name');
            $table->string('attendee_email');
            // sha256 hex of the opaque QR token — the raw token is emailed/shown but NEVER stored.
            $table->string('token_hash', 64)->unique();
            $table->string('status', 20)->default('valid');
            $table->timestamps();

            // One ticket per email per event (idempotent registration + anti-spam).
            $table->unique(['event_id', 'attendee_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
