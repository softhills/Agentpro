<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments and the 3D capture workflow (M4, M11).
 *
 * The state machine here has one job beyond bookkeeping: FR-M4-07 says a lister
 * who has paid but not booked must never reach a dead end. That is why the
 * entitlement lives on the order and the scan job is created separately — a
 * paid order with no scan_job is a recoverable state, not a lost payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('item_type', ['scan_3d', 'realsure', 'boost', 'subscription']);

            // FR-M11-03: priced server-side from configuration. The client never
            // sends an amount (SEC-05).
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('NGN');
            $table->string('price_version', 32)->nullable();

            $table->enum('state', [
                'pending', 'paid', 'failed', 'refunded', 'partially_refunded', 'cancelled',
            ])->default('pending');

            $table->string('paystack_reference')->nullable()->unique();
            $table->string('paystack_channel', 32)->nullable();  // card | bank_transfer | ussd
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('receipt_sent_at')->nullable();

            $table->decimal('refunded_amount', 15, 2)->default(0);
            $table->string('refund_reason')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'state']);
            $table->index(['item_type', 'state']);
        });

        // SEC-05: idempotency. A replayed webhook must not double-credit an order.
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 32)->default('paystack');
            $table->string('event_id')->unique();      // provider's own id — the idempotency key
            $table->string('event_type', 64);
            $table->json('payload');
            $table->boolean('signature_valid')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('event_type');
        });

        Schema::create('scan_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();

            // FR-M4-06
            $table->enum('state', [
                'requested', 'paid', 'scheduled', 'captured',
                'processing', 'live', 'rescheduled', 'cancelled', 'refunded',
            ])->default('requested');

            $table->timestamp('scheduled_for')->nullable();
            $table->unsignedTinyInteger('slot_hours')->default(2);
            $table->timestamp('attended_at')->nullable();
            $table->string('capture_reference')->nullable();   // Matterport space id
            $table->unsignedTinyInteger('reschedule_count')->default(0);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['state', 'scheduled_for']);
            $table->index('technician_id');
        });

        // Bookable capacity per area. FR-M4-05 shows real availability, and risk R2
        // says slots must be capped to what the field team can actually service.
        Schema::create('technician_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('slot_date');
            $table->time('slot_start');
            $table->unsignedTinyInteger('capacity')->default(1);
            $table->unsignedTinyInteger('booked')->default(0);
            $table->timestamps();

            $table->unique(['area_id', 'technician_id', 'slot_date', 'slot_start'], 'slot_unique');
            $table->index(['area_id', 'slot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_slots');
        Schema::dropIfExists('scan_jobs');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('orders');
    }
};
