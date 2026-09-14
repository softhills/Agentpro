<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a listing left the market, and why it is in the archive.
 *
 * `updated_at` cannot answer this: a lister who fixes a typo on a sold listing
 * moves it to the top of "recently sold", which turns a page of genuine
 * activity into a page anybody can bump. `published_at` answers the opposite
 * question. So the closing gets its own stamp, written once by the transition
 * that closes the listing.
 *
 * Nullable, and the public archive requires it to be set. A row that reached
 * sold or rented by some other route — a fixture, a manual correction, an
 * import — is not evidence of a transaction on this platform, and should not
 * be counted as one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('expires_at');

            // The archive's only query: closed listings of one state, newest
            // first. Composite in that order so the index does both halves.
            $table->index(['lifecycle_state', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex(['lifecycle_state', 'closed_at']);
            $table->dropColumn('closed_at');
        });
    }
};
