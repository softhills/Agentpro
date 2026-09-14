<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listing tags (FR-M2-04).
 *
 * Three of the four tags in the requirement had nowhere to live. `Vocab::TAGS`
 * named them, the search filter in FR-M5-03 listed them, and nothing on a
 * property could record one — so "Payment plan available" was a phrase in a
 * constant rather than something a lister could say about their listing.
 *
 * JSON rather than three boolean columns, because the set is expected to grow —
 * a tag is a marketing label, and marketing labels are exactly the thing a
 * business asks to add on a Tuesday. A column per tag means a migration per
 * request, which is how a schema ends up with `is_hot_deal` in it.
 *
 * The fourth tag, price_drop, is deliberately NOT stored. The requirement says
 * it is "applied automatically from price history, not chosen by the lister",
 * and the moment it becomes a column somebody can set, it will be set by
 * somebody whose price never moved. It stays derived, and the filter derives it
 * the same way the badge on the card does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('finish');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};
