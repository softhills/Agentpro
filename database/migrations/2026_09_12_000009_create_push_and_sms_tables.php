<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web push and SMS delivery (FR-M9-08).
 *
 * Both were declared as channels from the start and both fell back to the
 * in-app inbox. This is what they needed to become real.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * One row per browser, not per person.
         *
         * A push subscription belongs to an installation: the same account on a
         * phone and a laptop is two subscriptions, and clearing site data makes
         * a third. That is why this is a table rather than a column, and why
         * pruning matters — a subscription the browser has revoked stays
         * revoked forever, and every notification would otherwise queue a job
         * and an HTTP request for it indefinitely.
         */
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Endpoints run to several hundred characters and are not
            // index-friendly, so uniqueness is enforced on a hash of the value.
            // Without it, a browser that re-subscribes accumulates duplicates
            // and the user gets the same notification three times.
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();

            // RFC 8291 key material, base64url as the browser emits it.
            $table->string('p256dh', 128);
            $table->string('auth', 48);

            $table->string('user_agent')->nullable();   // so a person can tell their devices apart
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);

            $table->timestamps();

            $table->index(['user_id', 'last_used_at']);
        });

        /*
         * A record of every SMS, because SMS is the only channel that costs
         * money per message.
         *
         * Segments rather than a message count: the network bills per segment,
         * and "we sent 4,000 messages" and "we were billed for 11,000" are both
         * true of the same month. Without this the first number is all anyone
         * can produce, and the invoice is unanswerable.
         */
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('to_phone', 20);
            $table->string('body', 500);
            $table->unsignedTinyInteger('segments')->default(1);
            $table->boolean('transactional')->default(true);

            $table->string('provider', 32);
            $table->string('provider_message_id')->nullable();
            $table->enum('state', ['sent', 'failed'])->default('sent');
            $table->string('failure_reason')->nullable();
            $table->decimal('cost', 10, 2)->nullable();

            // What it was about, so a spike can be traced to a feature rather
            // than just to a date.
            $table->string('notification_type', 64)->nullable();

            $table->timestamps();

            $table->index(['state', 'created_at']);
            $table->index('notification_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('push_subscriptions');
    }
};
