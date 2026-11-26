<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Back-channel logout revocations (nexo-sso-client v2). Additive: standalone
// installs keep it empty. Rows expire after revocation_ttl_hours and
// `nexo-sso:prune` deletes them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nexo_sso_revocations', function (Blueprint $table): void {
            $table->id();
            $table->string('sid')->nullable()->index();
            $table->string('sub')->nullable()->index();
            $table->timestamp('revoked_at');
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nexo_sso_revocations');
    }
};
