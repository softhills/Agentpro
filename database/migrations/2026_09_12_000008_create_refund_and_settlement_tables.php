<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds and settlement reconciliation (FR-M11-05, FR-M11-06).
 *
 * Until now a refund was two columns on the order — a total and a reason. That
 * is enough to describe a refund that has already happened and nothing else,
 * which is the one state a refund is almost never in. A real refund is asked
 * for by one person, approved by another, sent to the provider, and lands in
 * the payer's account days later; each of those can fail on its own. So it gets
 * its own row with its own lifecycle, and the order keeps a denormalised total
 * for display.
 *
 * Settlement is the other half of the same question. The application knows what
 * it *charged*; only the provider knows what was actually paid into the bank,
 * net of fees and deductions. Reconciliation is what turns the first into
 * evidence for the second, and its most valuable output is not the matches —
 * it is the two kinds of mismatch: money that settled with no order behind it,
 * and orders that were marked paid and never settled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->default('paystack');
            $table->string('provider_id', 64);
            $table->string('status', 32)->default('pending');
            $table->string('currency', 3)->default('NGN');
            $table->date('settlement_date')->nullable();

            // As the provider reports them. total - fees - deductions should
            // equal effective; storing all four means we can check rather than
            // assume.
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('total_fees', 15, 2)->default(0);
            $table->decimal('deductions', 15, 2)->default(0);
            $table->decimal('effective_amount', 15, 2)->default(0);

            $table->timestamp('provider_created_at')->nullable();

            // Our side of the comparison.
            $table->unsignedInteger('transactions_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('unmatched_count')->default(0);
            $table->decimal('matched_amount', 15, 2)->default(0);
            $table->decimal('unmatched_amount', 15, 2)->default(0);

            /*
             * The gap between what the provider says the settlement totalled and
             * what the transactions we pulled add up to. It is not a money
             * problem — it is a *reconciliation* problem: a non-zero value means
             * we did not see every transaction, so every other number on the row
             * is drawn from an incomplete set and must not be trusted.
             */
            $table->decimal('variance', 15, 2)->default(0);

            $table->enum('reconciliation_state', ['unreconciled', 'balanced', 'discrepancy'])
                ->default('unreconciled');
            $table->timestamp('reconciled_at')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
            $table->index('settlement_date');
            $table->index('reconciliation_state');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('NGN');
            $table->string('reason');

            /*
             * Our lifecycle, deliberately not the provider's.
             *
             *   requested  awaiting a second approver (over the threshold)
             *   submitted  accepted by the provider, money not yet in the payer's account
             *   processed  the provider says it landed
             *   failed     the provider rejected or reversed it
             *   cancelled  withdrawn here before it was ever sent
             */
            $table->enum('state', ['requested', 'submitted', 'processed', 'failed', 'cancelled'])
                ->default('requested');

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->string('provider', 32)->default('paystack');
            $table->string('provider_refund_id')->nullable();
            // The provider's own vocabulary, kept verbatim. Mapping it into our
            // states loses detail that matters when chasing a stuck refund.
            $table->string('provider_status', 32)->nullable();
            $table->string('failure_reason')->nullable();
            $table->date('expected_at')->nullable();

            // Refunds come out of a settlement as a deduction, which is how the
            // provider's arithmetic balances.
            $table->foreignId('settlement_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['state', 'submitted_at']);
            $table->index('order_id');
            $table->unique(['provider', 'provider_refund_id'], 'refund_provider_unique');
        });

        Schema::create('settlement_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            // Null is the finding, not a gap in the data: money settled that no
            // order on this system accounts for.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            $table->string('provider_transaction_id', 64);
            $table->string('reference')->nullable();
            $table->decimal('amount', 15, 2);
            $table->decimal('fees', 15, 2)->default(0);
            $table->string('channel', 32)->nullable();
            $table->string('customer_email')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->unique(['settlement_id', 'provider_transaction_id'], 'settlement_txn_unique');
            $table->index('reference');
            $table->index('order_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Answering "has this money actually arrived?" — which is a
            // different question from "was this order paid?", and the one
            // Finance is asked.
            $table->foreignId('settlement_id')->nullable()->after('paid_at')
                ->constrained()->nullOnDelete();
            $table->timestamp('settled_at')->nullable()->after('settlement_id');
            $table->decimal('fees_amount', 15, 2)->nullable()->after('settled_at');
            $table->decimal('net_amount', 15, 2)->nullable()->after('fees_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settlement_id');
            $table->dropColumn(['settled_at', 'fees_amount', 'net_amount']);
        });

        Schema::dropIfExists('settlement_transactions');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('settlements');
    }
};
