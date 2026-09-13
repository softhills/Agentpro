<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Analytics (M13).
 *
 * Two tables, and the second is the reason the first can stay small.
 *
 * `analytics_events` holds raw seeker-side events and is deliberately the most
 * data-poor table in the schema: no IP address, no user agent, no user id, and
 * a visitor id only when somebody has agreed to one. What is not collected
 * cannot leak, cannot be subpoenaed and does not have to be erased later — and
 * every column here was argued for rather than added because it might be handy.
 *
 * `analytics_daily` is the rolled-up form. It exists for two reasons that point
 * the same way: a dashboard that aggregates four million rows on every load
 * stops being opened, and keeping raw behavioural events indefinitely is poor
 * practice under NDPA's data-minimisation principle. The rollup is what makes
 * pruning the raw table possible, so it is as much a privacy control as a
 * performance one.
 *
 * The lister funnel — register, verify, submit, publish, upgrade — is NOT here.
 * Every step of it is already a timestamp on `users`, `properties` or `orders`,
 * so recording it again would add a second version of the truth that can drift
 * from the first. It is derived at read time instead. @see App\Queries\Funnel
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();

            /*
             * A closed list, enforced in App\Support\Analytics rather than as a
             * database enum: the set will grow, and a migration per new event
             * would guarantee the instrumentation lags the product. What the
             * enum would have bought — no junk in the column — is bought
             * instead by the allowlist on the browser endpoint, which is the
             * only untrusted way in.
             */
            $table->string('name', 32);

            $table->foreignId('property_id')->nullable()->constrained()->cascadeOnDelete();

            /*
             * The stitching key, and the only thing here that could identify
             * anyone. Written ONLY when the visitor has accepted analytics
             * cookies (FR-M13-04); null otherwise, which is the common case and
             * has to remain useful. Counting that an event happened needs no
             * identifier at all — a total is not personal data — so without
             * consent the totals stay complete and only journey-level analysis
             * degrades.
             */
            $table->char('visitor_id', 32)->nullable();

            // Dwell seconds, or a result count. Nullable because most events
            // are an occurrence rather than a measurement.
            $table->unsignedInteger('value')->nullable();

            // One dimension, not an open JSON bag: contact mode, media kind.
            // A JSON column here would become a dumping ground within a month.
            $table->string('context', 32)->nullable();

            $table->timestamp('occurred_at')->useCurrent();

            // The per-listing screen: one property, one event kind, a window.
            $table->index(['property_id', 'name', 'occurred_at']);
            // The rollup and the funnel: everything of one kind in a window.
            $table->index(['name', 'occurred_at']);
            // Journey reconstruction for consenting visitors only.
            $table->index(['visitor_id', 'occurred_at']);
        });

        Schema::create('analytics_daily', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('name', 32);

            // Null means "across the whole platform" — the same row shape
            // serves the lister's per-listing screen and the admin totals,
            // rather than two tables that can disagree.
            $table->foreignId('property_id')->nullable()->constrained()->cascadeOnDelete();

            $table->unsignedInteger('events')->default(0);

            /*
             * Kept apart so an average can be recomputed from any combination
             * of rows. Storing a pre-divided average would make "mean dwell
             * across September" unanswerable, because averaging thirty averages
             * is not the average.
             */
            $table->unsignedBigInteger('value_sum')->nullable();
            $table->unsignedInteger('value_count')->nullable();

            // How much of the day's traffic agreed to be followed. Carried on
            // the rollup so a funnel read months later still knows what share
            // of people it could see — a conversion rate over consenting
            // visitors alone is not a conversion rate, and nothing should be
            // able to present it as one.
            $table->unsignedInteger('consented')->default(0);

            $table->timestamps();

            $table->index(['name', 'day']);
        });

        /*
         * The uniqueness that matters cannot be expressed with a plain unique
         * index here: `property_id` is null on every platform-wide row, and
         * MariaDB treats nulls as distinct, so (day, 'search', NULL) could be
         * inserted a hundred times without complaint — exactly the rows a
         * double-run of the rollup would duplicate, and exactly the ones a
         * dashboard sums.
         *
         * A stored generated column collapses null to 0 and gives the index
         * something real to constrain. Raw DDL because Laravel's schema builder
         * has no generated-column API, the same reason the spatial POINT column
         * on `properties` is declared this way.
         */
        DB::statement(
            'ALTER TABLE analytics_daily '
            .'ADD COLUMN property_key BIGINT UNSIGNED AS (COALESCE(property_id, 0)) STORED'
        );

        DB::statement(
            'ALTER TABLE analytics_daily '
            .'ADD UNIQUE KEY analytics_daily_unique (day, name, property_key)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_daily');
        Schema::dropIfExists('analytics_events');
    }
};
