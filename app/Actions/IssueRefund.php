<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\RefundResult;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Refunds (FR-M11-05).
 *
 * This reverses an earlier decision, and the reasoning is worth writing down
 * because the old comment is still in the history. Refunds used to be recorded
 * here and issued by hand in the Paystack dashboard, on the grounds that the
 * application should not have the authority to move money. That was the wrong
 * shape of caution. A refund is not a transfer: the provider sends it back
 * along the original transaction, to whoever paid, and nowhere else — so a
 * stolen admin session cannot use it to take money out of the business. What it
 * could do is *destroy* revenue by refunding everything, and the answer to that
 * is a limit and a second pair of eyes, not a manual step that nobody performs
 * consistently and that reconciliation then cannot verify.
 *
 * So: any admin can ask. Small refunds go straight out. Large ones wait for a
 * different admin. Nothing is ever marked refunded until the provider confirms
 * the money moved.
 */
class IssueRefund
{
    public function __construct(private PaymentGateway $gateway) {}

    /**
     * Ask for a refund. Sends it immediately if it is under the threshold.
     *
     * @throws RuntimeException when the amount is not refundable
     */
    public function request(Order $order, User $actor, float $amount, string $reason): Refund
    {
        if (! in_array($order->state, ['paid', 'partially_refunded'], true)) {
            throw new RuntimeException('Only a paid order can be refunded.');
        }

        // Re-read inside the transaction: two operators on the same order is
        // exactly the case this guard exists for, and checking outside it is a
        // race that ends with the provider processing both.
        $refund = DB::transaction(function () use ($order, $actor, $amount, $reason) {
            $order = Order::lockForUpdate()->find($order->id);
            $available = $order->refundableAmount();

            if ($amount > $available + 0.009) {
                throw new RuntimeException(sprintf(
                    'Only %s is still refundable on this order; %s is already refunded or awaiting approval.',
                    number_format($available, 2),
                    number_format((float) $order->amount - $available, 2),
                ));
            }

            $refund = Refund::create([
                'uuid'         => Str::uuid(),
                'order_id'     => $order->id,
                'amount'       => $amount,
                'currency'     => $order->currency,
                'reason'       => $reason,
                'state'        => 'requested',
                'requested_by' => $actor->id,
                'provider'     => $this->gateway->name(),
            ]);

            Audit::record('refund.requested', $refund, [], [
                'order_id' => $order->id,
                'amount'   => $amount,
                'reason'   => $reason,
            ], $actor->id);

            return $refund;
        });

        // Sending happens outside the transaction on purpose: an HTTP call to
        // the provider inside an open row lock holds that lock for the length of
        // somebody else's network, and a timeout would roll back the record of a
        // refund that may well have been accepted.
        if ($this->needsSecondApprover($amount)) {
            return $refund;
        }

        return $this->approve($refund, $actor, selfApprovalAllowed: true);
    }

    /**
     * Approve a waiting refund and send it.
     *
     * The approver must not be the requester. That is the entire control: one
     * compromised account can propose a large refund but cannot complete it.
     */
    public function approve(Refund $refund, User $actor, bool $selfApprovalAllowed = false): Refund
    {
        if ($refund->state !== 'requested') {
            throw new RuntimeException('That refund is no longer waiting for approval.');
        }

        if (! $selfApprovalAllowed && $refund->requested_by === $actor->id) {
            throw new RuntimeException(
                'A refund this size has to be approved by someone other than the person who asked for it.'
            );
        }

        $refund->update(['approved_by' => $actor->id, 'approved_at' => now()]);

        Audit::record('refund.approved', $refund, ['state' => 'requested'], [
            'amount'   => (float) $refund->amount,
            'order_id' => $refund->order_id,
        ], $actor->id);

        return $this->send($refund);
    }

    public function cancel(Refund $refund, User $actor, string $why): Refund
    {
        if ($refund->state !== 'requested') {
            throw new RuntimeException('Only a refund that has not been sent can be cancelled.');
        }

        $refund->update(['state' => 'cancelled', 'failure_reason' => $why]);

        Audit::record('refund.cancelled', $refund, ['state' => 'requested'], ['reason' => $why], $actor->id);

        return $refund;
    }

    /**
     * Hand it to the provider.
     *
     * The failure path matters more than the success path here. If the call
     * throws, the refund stays visible as failed with the reason attached
     * rather than disappearing — an operator who sees nothing will simply try
     * again, and a refund that was actually accepted before the timeout would
     * then go out twice.
     */
    public function send(Refund $refund): Refund
    {
        try {
            $result = $this->gateway->refund(
                $refund->order,
                (float) $refund->amount,
                $refund->reason,
            );
        } catch (\Throwable $e) {
            Log::error('refund.submit_failed', [
                'refund_id' => $refund->id,
                'order_id'  => $refund->order_id,
                'message'   => $e->getMessage(),
            ]);

            $refund->update([
                'state'          => 'failed',
                'failure_reason' => Str::limit($e->getMessage(), 200),
            ]);

            Audit::record('refund.failed', $refund, ['state' => 'requested'], [
                'reason' => Str::limit($e->getMessage(), 200),
            ]);

            throw new RuntimeException(
                'The provider did not accept the refund: '.$e->getMessage()
            );
        }

        $refund->update([
            'state'              => 'submitted',
            'submitted_at'       => now(),
            'provider_refund_id' => $result->providerId ?: null,
            'provider_status'    => $result->status,
            'expected_at'        => $result->expectedAt,
        ]);

        Audit::record('refund.submitted', $refund, ['state' => 'requested'], [
            'provider'        => $this->gateway->name(),
            'provider_status' => $result->status,
            'amount'          => (float) $refund->amount,
        ]);

        // A provider that completes synchronously (some do for wallet balances)
        // should not leave the refund sitting in "submitted" forever.
        if ($result->isProcessed()) {
            $this->settle($refund, $result);
        }

        return $refund->fresh();
    }

    /**
     * Apply what the provider says about a refund — from a webhook or a poll.
     *
     * Idempotent, because both routes exist and both will fire for the same
     * refund on a normal day.
     */
    public function settle(Refund $refund, RefundResult $result): bool
    {
        if (in_array($refund->state, ['processed', 'failed', 'cancelled'], true)) {
            return false;
        }

        $before = ['state' => $refund->state, 'provider_status' => $refund->provider_status];

        if ($result->isProcessed()) {
            $refund->update([
                'state'           => 'processed',
                'provider_status' => $result->status,
                'processed_at'    => now(),
            ]);

            $refund->order->syncRefundState();

            Audit::record('refund.processed', $refund, $before, [
                'state'    => 'processed',
                'amount'   => (float) $refund->amount,
                'order_id' => $refund->order_id,
            ]);

            return true;
        }

        if ($result->hasFailed()) {
            $refund->update([
                'state'           => 'failed',
                'provider_status' => $result->status,
                'failure_reason'  => $result->failureReason ?: 'The provider reported '.$result->status.'.',
            ]);

            Audit::record('refund.failed', $refund, $before, [
                'state'  => 'failed',
                'reason' => $result->failureReason,
            ]);

            return true;
        }

        // Still in flight — record the provider's wording and nothing else.
        if ($result->status !== $refund->provider_status) {
            $refund->update(['provider_status' => $result->status]);
        }

        return false;
    }

    private function needsSecondApprover(float $amount): bool
    {
        return $amount > (float) config('agentpro.refunds.dual_approval_above');
    }
}
