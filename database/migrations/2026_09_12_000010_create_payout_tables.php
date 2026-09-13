<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paying listers (FR-M11-07).
 *
 * Everything before this moved money in one of two safe directions: into the
 * business, or back along the transaction that brought it in. A payout is the
 * first time money leaves to a destination somebody chose, which makes it the
 * only place in the system where a compromised account is worth money to an
 * attacker. The schema is shaped around that rather than around convenience.
 *
 * Three ideas, each with a table:
 *
 *  - A bank account is not what the user typed. It is what the bank says that
 *    account number resolves to, recorded with when we asked and when it
 *    becomes usable.
 *  - What is owed is a ledger, not a column. A balance that can be written to
 *    directly can be written to wrongly, and there is no way afterwards to ask
 *    where a figure came from.
 *  - A payout has a lifecycle with a human in it, and holds its money from the
 *    moment it is asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Where a lister's money goes.
         *
         * account_name is never taken from the user. It comes from the bank via
         * the provider's resolve endpoint, which is the only thing that makes
         * "is this really their account?" answerable at all — a typed name is
         * just a claim, and the number is what the money follows.
         */
        Schema::create('payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('bank_code', 10);
            $table->string('bank_name');
            $table->string('account_number', 20);
            // As the bank returned it. Displayed everywhere in preference to
            // anything the lister typed.
            $table->string('account_name');
            $table->timestamp('resolved_at')->nullable();

            /*
             * The single most useful control against account takeover.
             *
             * The attack is: compromise a lister's login, change the bank
             * details, withdraw. A hold between adding an account and being able
             * to send to it — with a message to the contact details on file —
             * is what turns that from a silent theft into something the real
             * owner can stop.
             */
            $table->timestamp('usable_from')->nullable();

            /*
             * Whether the bank's name for the account matches the identity we
             * verified. A mismatch is not refused: Nigerian agents legitimately
             * receive into a business account. It is flagged for a person.
             */
            $table->boolean('name_matches_identity')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // The provider's handle for this destination, created once.
            $table->string('recipient_code')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->unique(['user_id', 'bank_code', 'account_number'], 'payout_account_unique');
        });

        /*
         * What the business owes, as entries rather than a balance.
         *
         * Append-only. Nothing updates a row here; a correction is another row.
         * The balance is the sum, which means it can always be explained line by
         * line — and a balance that cannot be explained is one nobody can defend
         * when a lister disputes it.
         */
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->enum('direction', ['credit', 'debit']);
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('NGN');

            /*
             * Why this entry exists. A closed list, because "what are we paying
             * listers for" is a question Finance will be asked and free text
             * cannot answer it.
             */
            $table->enum('kind', [
                'listing_incentive',    // supply-side programme (objective O4)
                'referral',             // brought another verified lister
                'service_credit',       // goodwill after something went wrong
                'correction',           // fixing an earlier entry
                'payout',               // money reserved for, or sent in, a payout
                'payout_returned',      // a payout that failed or was reversed
            ]);

            $table->string('memo');
            $table->foreignId('payout_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // No updated_at: nothing here is ever updated.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('kind');
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payout_account_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('NGN');

            /*
             *   requested  money is held on the ledger, waiting for an approver
             *   submitted  accepted by the provider, not yet in the bank
             *   paid       the provider says it landed
             *   failed     rejected before it left
             *   reversed   it left and came back — the money returns to the ledger
             *   cancelled  withdrawn here before anything was sent
             */
            $table->enum('state', ['requested', 'submitted', 'paid', 'failed', 'reversed', 'cancelled'])
                ->default('requested');

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->string('provider', 32)->default('paystack');
            // The provider's idempotency key. Unique, so a retried submission
            // cannot become a second transfer.
            $table->string('provider_reference')->nullable()->unique();
            $table->string('provider_transfer_code')->nullable();
            $table->string('provider_status', 32)->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamps();

            $table->index(['state', 'submitted_at']);
            $table->index(['user_id', 'state']);
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->foreign('payout_id')->references('id')->on('payouts')->nullOnDelete();
        });

        DB::statement("ALTER TABLE ledger_entries COMMENT = 'Append-only. A correction is a new row, never an edit.'");
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['payout_id']);
        });

        Schema::dropIfExists('payouts');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('payout_accounts');
    }
};
