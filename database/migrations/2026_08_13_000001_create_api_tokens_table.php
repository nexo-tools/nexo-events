<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // What the organizer calls this integration ("Web La Herrería"), so a
            // token can be recognised — and revoked — months later.
            $table->string('name');
            // sha256 hex of the opaque token. The raw value is shown once at
            // creation and never stored, same as tickets.token_hash (ADR-004/008).
            $table->string('token_hash', 64)->unique();
            // Scopes: ["events:read", "events:write"].
            $table->json('abilities');
            $table->timestamp('last_used_at')->nullable();
            // Revocation is a timestamp, not a delete: the row stays as the record
            // that this integration existed and when it was cut off.
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
