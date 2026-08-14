<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The consumer's own key for an event it created through the integration API
 * (e.g. "laherreria:evento:42"), which is what makes creation idempotent: a
 * retry after a timeout finds the existing event instead of making a second one.
 *
 * DATABASE-STANDARD §11 — this adds a UNIQUE to a table with live rows, so the
 * data plan is written here rather than discovered in production:
 *
 *   What happens to the rows that already exist: nothing. The column is added
 *   nullable with no backfill, so every existing event gets NULL. A UNIQUE index
 *   does not constrain NULLs — in MySQL and in SQLite, any number of rows may
 *   share (organizer_id, NULL) — so the constraint cannot collide with anything
 *   already stored, and no cleanup query is needed. Events created through the
 *   web panel keep getting NULL forever; only the API ever writes this column.
 *
 *   What happens on rollback: the unique index and the column are dropped, which
 *   destroys the link between an external system's events and the ones here.
 *   That is not recoverable from this database — the mapping lives in the
 *   consumer, and after a rollback its retries would create duplicate events
 *   instead of finding the originals. Reverting is therefore safe only while the
 *   API has never been used by a consumer (which is the state it ships in:
 *   NEXO_API_ENABLED=false).
 *
 *   And the rollback is not the one-liner it looks like. Measured on MySQL 8.4:
 *   adding this unique makes InnoDB DROP the index it had auto-created for the
 *   organizer_id foreign key, because the new one leads with the same column and
 *   can enforce the key. The unique then IS the foreign key's index, and dropping
 *   it fails with errno 1553. So down() takes the key off first and puts it back
 *   afterwards, which restores exactly the schema that was there before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('external_ref', 190)->nullable()->after('slug');
            // Scoped to the organizer, never global: two accounts integrating two
            // different sites may legitimately use the same reference string.
            $table->unique(['organizer_id', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            // See the note above: on MySQL this unique is what backs the foreign
            // key by the time we get here, so the key comes off first. Re-adding
            // it makes InnoDB auto-create its own index again, as it was.
            $table->dropForeign(['organizer_id']);
            $table->dropUnique(['organizer_id', 'external_ref']);
            $table->dropColumn('external_ref');
            $table->foreign('organizer_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
