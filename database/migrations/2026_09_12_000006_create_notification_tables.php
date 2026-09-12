<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification delivery (M9).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Laravel's standard table, used for the in-app inbox.
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        /*
         * FR-M9-06: alerts are batched per listing within a rolling window, so
         * five edits in an afternoon produce one notification rather than five.
         *
         * Changes accumulate here until the window closes. Keeping the
         * individual changes rather than a counter means the notification can
         * say what actually changed, which is the difference between a useful
         * alert and noise.
         */
        Schema::create('pending_listing_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->json('changes');
            $table->timestamp('window_closes_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['dispatched_at', 'window_closes_at']);
        });

        /*
         * FR-M9-09: product feedback, independent of any listing.
         */
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 120)->nullable();
            $table->text('body');
            $table->string('page')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
        Schema::dropIfExists('pending_listing_alerts');
        Schema::dropIfExists('notifications');
    }
};
