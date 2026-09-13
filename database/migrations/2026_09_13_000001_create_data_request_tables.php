<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access and erasure requests (FR-M1-09, NDPA 2023 ss. 34 and 38).
 *
 * The Act gives a data subject two rights this table serves: to be given a copy
 * of what is held about them, and to have it erased. Both are recorded as
 * requests with a state rather than performed on the spot, for different
 * reasons.
 *
 * An export is recorded because the Act gives a deadline (one month) and a
 * regulator may ask when a request arrived and when it was answered. A row with
 * a created_at and a completed_at answers that; a file that was downloaded once
 * does not.
 *
 * An erasure is recorded because it must NOT happen on the spot. It is the most
 * destructive thing an account can do to itself and it cannot be undone, which
 * makes it worth something to somebody who has stolen a login — not to steal,
 * but to destroy. So it is held for a cooling-off period during which the real
 * owner is told and can stop it, exactly as a change of payout bank details is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_requests', function (Blueprint $table) {
            $table->id();
            // What the person and any support conversation refers to. The
            // numeric id is never shown: it leaks how many requests exist.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->enum('kind', ['export', 'erasure']);

            /*
             *   pending    accepted; an export is building, an erasure is in
             *              its cooling-off period
             *   ready      export only — the file exists and can be downloaded
             *   completed  the export was delivered, or the erasure was carried out
             *   expired    export only — the file was removed unread
             *   cancelled  withdrawn by the person before it ran
             *   refused    erasure only — something must be settled first
             *   failed     it broke; the reason is in `note`
             */
            $table->enum('state', [
                'pending', 'ready', 'completed', 'expired', 'cancelled', 'refused', 'failed',
            ])->default('pending');

            // Where the request came from. Not for the export — for the message
            // that warns the real owner, which is far more useful if it can say
            // "from a phone in Lagos" than if it can only say "from somewhere".
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();

            // Erasure: the end of the cooling-off period. Nothing is destroyed
            // before this, and signing in and cancelling is always possible up
            // to the moment it runs.
            $table->timestamp('executes_at')->nullable();

            // Export: the built file, on a private disk, behind a signed link
            // with a deliberately short life.
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_bytes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Why it was refused, or how it failed. Written for the person to
            // read, not for a log.
            $table->string('note')->nullable();

            $table->timestamps();

            // The scheduled command's only query: what is due to run.
            $table->index(['state', 'executes_at']);
            $table->index(['user_id', 'kind']);
        });

        Schema::table('users', function (Blueprint $table) {
            /*
             * The account row survives erasure with every personal field
             * overwritten, because orders, ledger entries and the audit trail
             * all point at it and are retained under a legal obligation the Act
             * itself recognises (s. 34(2)). Deleting the row would either take
             * those with it or leave them orphaned, and an audit trail whose
             * actor cannot be resolved is not an audit trail.
             *
             * This column is what separates "anonymised" from "never filled
             * in", which nothing else in the row can tell you afterwards.
             */
            $table->timestamp('anonymised_at')->nullable()->after('commute_lng');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('anonymised_at');
        });

        Schema::dropIfExists('data_requests');
    }
};
