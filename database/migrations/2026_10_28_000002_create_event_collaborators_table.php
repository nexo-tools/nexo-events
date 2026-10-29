<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event collaborators (ADR-011, SPEC-event-collaborators): one row per invited
 * email per event. A row is an invitation until it is accepted, and the record
 * of a grant afterwards — revocation is a timestamp, not a delete, so the owner
 * can still see who had access and until when.
 *
 * Data plan: new table, nothing existing changes; every event starts with no
 * collaborators, so an instance that never invites anyone behaves exactly as
 * before. Rollback: drop the table (no other table references it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_collaborators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            // Null until the invitation is accepted by an account with this email.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Lowercased on write: it is compared with the accepting account's email.
            $table->string('email', 255);
            // sha256 hex of the opaque invitation token. The raw value travels once
            // (the email, and once to whoever invites); cleared on accept and revoke.
            $table->char('invite_token_hash', 64)->nullable()->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            // Name of the API token that made the invitation, when one did.
            $table->string('invited_via', 60)->nullable();
            $table->timestamp('invited_at');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_collaborators');
    }
};
