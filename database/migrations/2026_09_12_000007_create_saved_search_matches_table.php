<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which listings a saved search has already reported (FR-M5-07).
 *
 * The obvious implementation is a timestamp watermark — notify about anything
 * published since the last run — and it is wrong in a way that matters here.
 * A listing published last week at ₦15M that drops to ₦11M today becomes a
 * match for a "under ₦12M" search without its published_at moving, so a
 * watermark would never report it. A price drop into range is arguably the most
 * valuable alert this feature can send, so the thing being tracked has to be
 * "have we told them about this listing", not "when did we last look".
 *
 * It also makes de-duplication exact rather than probabilistic: a listing
 * unpublished and republished cannot produce a second alert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_search_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saved_search_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['saved_search_id', 'property_id']);
            $table->index('notified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_search_matches');
    }
};
