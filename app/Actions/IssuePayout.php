<?php

namespace App\Actions;

use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Notifications\PayoutSent;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\TransferResult;
use App\Support\Audit;
use App\Support\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sending money to a lister (FR-M11-07).
 *
 * The shape follows refunds — request, approve, submit, settle — but the
 * controls are stricter, because the two are not the same risk. A refund can
 * only travel back along the transaction that paid it, so the worst a stolen
 * admin session can do is give money back to the people who paid it. A payout
 * goes wherever the destination says. Every safeguard here exists because of
 * that difference:
 *
 *  - The money is taken off the ledger when the payout is *requested*, not when
 *    it is sent. Otherwise two requests can each be for the whole balance.
 *  - A second admin always approves. There is no threshold below which this is
 *    skipped, unlike refunds.
 *  - The destination must be out of its cooling-off period at the moment of
 *    sending, re-checked rather than trusted from when the request was made.
 *  - The provider's balance is checked first, so "there was no money" is found
 *    before the payout is recorded as sent rather than after.
 *  - Nothing is ever retried automatically. A transfer that timed out may have
 *    succeeded.
 */
class IssuePayout
{
    public function __construct(private PaymentGateway $gateway) {}

    /**
     * Ask for a payout. Reserves the money; sends nothing.
     *
     * @throws RuntimeException when the balance or the destination will not allow it
     */
    public function request(User $lister, float $amount, User $actor): Payout
    {
        $account = $lister->activePayoutAccount();

        if (! $account) {
            throw new RuntimeException('There are no bank details on this account yet.');
        }

        if ($amount < (float) config('agentpro.payouts.minimum')) {
            throw new RuntimeException(
                'The smallest payout is '.\App\Support\Money::naira(config('agentpro.payouts.minimum')).'.'
            );
        }

        return DB::transaction(function () use ($lister, $amount, $actor, $account) {
            /*
             * Locked and re-read inside the transaction. Two tabs, or a lister
             * and an admin acting at once, is the case this exists for: read the
             * balance outside and both requests pass the check.
             */
            DB::table('users')->where('id', $lister->id)->lockForUpdate()->first();

            $balance = Ledger::balanceFor($lister);

            if ($amount > $balance + 0.009) {
                throw new RuntimeException(sprintf(
                    'Only %s is available; %s was requested.',
                    \App\Support\Money::naira($balance),
                    \App\Support\Money::naira($amount),
                ));
            }

            $payout = Payout::create([
                'uuid'              => Str::uuid(),
                'user_id'           => $lister->id,
                'payout_account_id' => $account->id,
                'amount'            => $amount,
                'currency'          => 'NGN',
                'state'             => 'requested',
                'requested_by'      => $actor->id,
                'provider'          => $this->gateway->name(),
                // Minted now and never regenerated, so a resubmission carries
                // the same idempotency key the provider already saw.
                'provider_reference' => 'po_'.Str::lower(Str::random(20)),
            ]);

            // The debit happens here, not at send. Money that is spoken for is
            // not available to a second request.
            Ledger::record(
                $lister,
                'debit',
                $amount,
                'payout',
                'Payout requested',
                $payout->id,
                $actor->id,
            );

            Audit::record('payout.requested', $payout, [], [
                'amount'  => $amount,
                'lister'  => $lister->id,
                'account' => $account->masked(),
            ], $actor->id);

            return $payout;
        });
    }

    /**
     * Approve and send.
     *
     * Always a different person from the requester, with no threshold below
     * which that is waived — see the class note for why payouts are treated
     * more strictly than refunds.
     */
    public function approve(Payout $payout, User $actor): Payout
    {
        if ($payout->state !== 'requested') {
            throw new RuntimeException('That payout is no longer waiting for approval.');
        }

        if ($payout->requested_by === $actor->id) {
            throw new RuntimeException(
                'A payout has to be approved by someone other than the person who asked for it.'
            );
        }

        $account = $payout->account;

        // Re-checked at the moment of sending. The hold could have been
        // satisfied when the request was made and the details changed since.
        if (! $account->isPayable()) {
            throw new RuntimeException($account->blocker() ?? 'That account cannot receive money yet.');
        }

        if (! $account->is_active) {
            throw new RuntimeException('The bank details have changed since this was requested. Cancel it and start again.');
        }

        $payout->update(['approved_by' => $actor->id, 'approved_at' => now()]);

        Audit::record('payout.approved', $payout, ['state' => 'requested'], [
            'amount' => (float) $payout->amount,
        ], $actor->id);

        return $this->send($payout);
    }

    /**
     * Cancel before anything leaves, returning the money to the ledger.
     */
    public function cancel(Payout $payout, User $actor, string $why): Payout
    {
        if ($payout->state !== 'requested') {
            throw new RuntimeException('Only a payout that has not been sent can be cancelled.');
        }

        DB::transaction(function () use ($payout, $actor, $why) {
            $payout->update(['state' => 'cancelled', 'failure_reason' => $why]);

            Ledger::record(
                $payout->user_id,
                'credit',
                (float) $payout->amount,
                'payout_returned',
                'Payout cancelled: '.$why,
                $payout->id,
                $actor->id,
            );

            Audit::record('payout.cancelled', $payout, ['state' => 'requested'], ['reason' => $why], $actor->id);
        });

        return $payout->fresh();
    }

    /** Hand it to the provider. */
    public function send(Payout $payout): Payout
    {
        $account = $payout->account;

        if (! $account->recipient_code) {
            try {
                $account->update([
                    'recipient_code' => $this->gateway->createRecipient(
                        $account->account_number,
                        $account->bank_code,
                        $account->account_name,
                    ),
                ]);
            } catch (\Throwable $e) {
                return $this->fail($payout, $e->getMessage(), returnToLedger: true);
            }
        }

        // Asked before sending, so an empty float is found here rather than
        // after the payout has been recorded as sent.
        $balance = $this->gateway->balance();

        if ($balance !== null && $balance < (float) $payout->amount) {
            throw new RuntimeException(sprintf(
                'The provider balance is %s, which is less than this payout. Top up before approving it.',
                \App\Support\Money::naira($balance),
            ));
        }

        try {
            $result = $this->gateway->transfer(
                $account->recipient_code,
                (float) $payout->amount,
                $payout->provider_reference,
                'Agentpro payout',
            );
        } catch (\Throwable $e) {
            Log::error('payout.submit_failed', [
                'payout_id' => $payout->id,
                'message'   => $e->getMessage(),
            ]);

            /*
             * The money stays off the ledger.
             *
             * This call may have reached the provider before it failed, so
             * crediting the balance back here could let the same money be paid
             * out twice. It is returned deliberately, by a person, once the
             * transfer's real fate is known — which is what the failed state on
             * the payouts screen is for.
             */
            $this->fail($payout, Str::limit($e->getMessage(), 200), returnToLedger: false);

            throw new RuntimeException('The provider did not accept the transfer: '.$e->getMessage());
        }

        $payout->update([
            'state'                  => 'submitted',
            'submitted_at'           => now(),
            'provider_transfer_code' => $result->transferCode,
            'provider_status'        => $result->status,
        ]);

        Audit::record('payout.submitted', $payout, ['state' => 'requested'], [
            'provider_status' => $result->status,
            'amount'          => (float) $payout->amount,
        ]);

        if ($result->needsOtp()) {
            // Not an error, and not something this application can resolve:
            // nothing here can read the one-time code. Surfaced rather than
            // buried in "pending", because somebody has to go and finish it.
            Log::warning('payout.awaiting_otp', ['payout_id' => $payout->id]);
        }

        if ($result->isPaid()) {
            $this->settle($payout, $result);
        }

        return $payout->fresh();
    }

    /**
     * Apply the provider's verdict — from a webhook or a poll.
     *
     * Idempotent: both routes fire for the same payout on an ordinary day.
     */
    public function settle(Payout $payout, TransferResult $result): bool
    {
        if (in_array($payout->state, ['paid', 'failed', 'reversed', 'cancelled'], true)) {
            return false;
        }

        $before = ['state' => $payout->state, 'provider_status' => $payout->provider_status];

        if ($result->isPaid()) {
            $payout->update([
                'state'           => 'paid',
                'provider_status' => $result->status,
                'paid_at'         => now(),
            ]);

            Audit::record('payout.paid', $payout, $before, ['amount' => (float) $payout->amount]);

            $payout->user?->notify(new PayoutSent($payout));

            return true;
        }

        if ($result->hasFailed()) {
            $this->fail($payout, $result->failureReason ?: 'The provider reported '.$result->status.'.', returnToLedger: true);

            return true;
        }

        /*
         * Reversed is not failed. The transfer left, the bank could not deliver
         * it, and it came back days later — so the money returns to the ledger
         * exactly as with a failure, but the payout is recorded differently
         * because a pattern of reversals means the destination is wrong and
         * needs a person, not a retry.
         */
        if ($result->wasReversed()) {
            DB::transaction(function () use ($payout, $result, $before) {
                $payout->update([
                    'state'           => 'reversed',
                    'provider_status' => $result->status,
                    'failure_reason'  => $result->failureReason ?: 'The bank returned the transfer.',
                ]);

                Ledger::record(
                    $payout->user_id,
                    'credit',
                    (float) $payout->amount,
                    'payout_returned',
                    'Transfer returned by the bank',
                    $payout->id,
                );

                Audit::record('payout.reversed', $payout, $before, ['amount' => (float) $payout->amount]);
            });

            return true;
        }

        if ($result->status !== $payout->provider_status) {
            $payout->update(['provider_status' => $result->status]);
        }

        return false;
    }

    /**
     * @param  bool  $returnToLedger  false when the transfer's fate is unknown
     */
    private function fail(Payout $payout, string $reason, bool $returnToLedger): Payout
    {
        DB::transaction(function () use ($payout, $reason, $returnToLedger) {
            $payout->update([
                'state'          => 'failed',
                'failure_reason' => $reason,
            ]);

            if ($returnToLedger) {
                Ledger::record(
                    $payout->user_id,
                    'credit',
                    (float) $payout->amount,
                    'payout_returned',
                    'Payout failed: '.$reason,
                    $payout->id,
                );
            }

            Audit::record('payout.failed', $payout, [], [
                'reason'            => $reason,
                'returned_to_ledger' => $returnToLedger,
            ]);
        });

        return $payout->fresh();
    }

    /**
     * Put the money back by hand, once a stuck transfer's real fate is known.
     *
     * The deliberate counterpart to not crediting automatically on a submission
     * failure. Guarded so it cannot be applied twice.
     */
    public function returnToLedger(Payout $payout, User $actor, string $why): Payout
    {
        if ($payout->state !== 'failed') {
            throw new RuntimeException('Only a failed payout can be returned to the ledger.');
        }

        if ($payout->ledgerEntries()->where('kind', 'payout_returned')->exists()) {
            throw new RuntimeException('This payout has already been returned to the ledger.');
        }

        DB::transaction(function () use ($payout, $actor, $why) {
            Ledger::record(
                $payout->user_id,
                'credit',
                (float) $payout->amount,
                'payout_returned',
                'Returned by hand: '.$why,
                $payout->id,
                $actor->id,
            );

            Audit::record('payout.returned_to_ledger', $payout, [], [
                'amount' => (float) $payout->amount,
                'reason' => $why,
            ], $actor->id);
        });

        return $payout->fresh();
    }
}
