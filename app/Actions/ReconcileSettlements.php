<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\Refund;
use App\Models\Settlement;
use App\Models\SettlementTransaction;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\SettledTransaction;
use App\Services\Payments\SettlementRecord;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily settlement reconciliation (FR-M11-06).
 *
 * The application knows what it charged. Only the provider knows what was
 * actually paid into the bank, net of fees and of refunds taken back out. This
 * is the job that compares the two, and it is worth being clear that the
 * matches are not the output — the mismatches are, and there are three kinds
 * that each mean something different:
 *
 *  - A settled transaction with no order behind it. Somebody paid us and this
 *    system does not know. That is a customer who has been charged and has
 *    nothing, and it is almost always a webhook that never arrived.
 *  - A paid order that never settles. We told someone their payment succeeded
 *    and the money never came. Either a reversal we did not see, or a reference
 *    we are matching on wrongly.
 *  - A settlement whose transactions do not add up to its own stated total.
 *    That is not a money problem, it is a *reconciliation* problem: we did not
 *    see every transaction, so neither of the findings above can be trusted for
 *    that settlement, and the run says so rather than reporting confident
 *    nonsense.
 *
 * Refunds are polled in the same pass. They have webhooks, but a refund sits in
 * flight for days and a single missed delivery would strand it — and an
 * unresolved refund is money the business thinks it has given back and hasn't.
 */
class ReconcileSettlements
{
    public function __construct(
        private PaymentGateway $gateway,
        private IssueRefund $refunds,
    ) {}

    public function run(?Carbon $from = null, ?Carbon $to = null): ReconciliationReport
    {
        $to ??= now();
        $from ??= $to->copy()->subDays((int) config('agentpro.settlement.lookback_days'));

        $report = new ReconciliationReport();

        try {
            $records = $this->gateway->settlements($from, $to);
        } catch (\Throwable $e) {
            // A failed fetch must not look like a clean run. Nothing is written,
            // and the problem is carried out to the caller to be shown.
            Log::error('settlement.fetch_failed', ['message' => $e->getMessage()]);
            $report->problems[] = 'Could not read settlements from the provider: '.$e->getMessage();

            return $report;
        }

        foreach ($records as $record) {
            $settlement = $this->upsertSettlement($record);
            $report->settlements++;

            if (! $record->isPaidOut()) {
                // Still in flight at the provider. Its transaction list is not
                // final, so matching against it would produce findings that
                // resolve themselves tomorrow.
                continue;
            }

            $this->reconcile($settlement, $report);
        }

        $report->unsettledOrders = $this->unsettledOrders()->count();
        $this->pollRefunds($report);

        Audit::record('settlement.reconciled', null, [], [
            'from'          => $from->toDateString(),
            'to'            => $to->toDateString(),
            'settlements'   => $report->settlements,
            'unmatched'     => $report->orphans,
            'discrepancies' => $report->discrepancies,
        ]);

        return $report;
    }

    /**
     * Paid orders the money never arrived for.
     *
     * The grace period is not cosmetic: a payment taken this afternoon has not
     * settled yet and never should have, so counting it as missing would bury
     * the genuine cases under a daily wave of false ones.
     */
    public function unsettledOrders()
    {
        $grace = (int) config('agentpro.settlement.grace_days');

        return Order::whereIn('state', ['paid', 'partially_refunded', 'refunded'])
            ->whereNull('settlement_id')
            ->whereNotNull('paid_at')
            ->where('paid_at', '<', now()->subDays($grace))
            ->orderBy('paid_at');
    }

    private function upsertSettlement(SettlementRecord $record): Settlement
    {
        return Settlement::updateOrCreate(
            ['provider' => $this->gateway->name(), 'provider_id' => $record->providerId],
            [
                'status'              => $record->status,
                'currency'            => $record->currency,
                'settlement_date'     => $record->settlementDate,
                'total_amount'        => $record->total(),
                'total_fees'          => $record->fees(),
                'deductions'          => $record->deductions(),
                'effective_amount'    => $record->effective(),
                'provider_created_at' => $record->createdAt,
            ]
        );
    }

    private function reconcile(Settlement $settlement, ReconciliationReport $report): void
    {
        try {
            $transactions = $this->gateway->settlementTransactions($settlement->provider_id);
        } catch (\Throwable $e) {
            // Deliberately not marked reconciled. Leaving it "unreconciled" is
            // the honest state; marking it balanced on a list we failed to read
            // would be the worst possible outcome of this whole feature.
            Log::error('settlement.transactions_failed', [
                'settlement' => $settlement->provider_id,
                'message'    => $e->getMessage(),
            ]);
            $report->problems[] = 'Settlement '.$settlement->provider_id.': '.$e->getMessage();

            return;
        }

        $matchedCount = 0;
        $matchedTotal = 0.0;
        $seenTotal    = 0.0;
        $orphans      = 0;
        $orphanTotal  = 0.0;

        DB::transaction(function () use (
            $settlement, $transactions,
            &$matchedCount, &$matchedTotal, &$seenTotal, &$orphans, &$orphanTotal
        ) {
            foreach ($transactions as $transaction) {
                $order = $this->matchOrder($transaction);
                $seenTotal += $transaction->amount();

                SettlementTransaction::updateOrCreate(
                    [
                        'settlement_id'          => $settlement->id,
                        'provider_transaction_id' => $transaction->providerId,
                    ],
                    [
                        // Recorded even when the order is not confirmed paid:
                        // knowing *which* order the money was for is the whole
                        // value of the row to whoever has to resolve it.
                        'order_id'       => $order?->id,
                        'reference'      => $transaction->reference,
                        'amount'         => $transaction->amount(),
                        'fees'           => $transaction->fees(),
                        'channel'        => $transaction->channel,
                        'customer_email' => $transaction->customerEmail,
                        'paid_at'        => $transaction->paidAt,
                    ]
                );

                /*
                 * Finding an order is not the same as reconciling the money.
                 *
                 * An order still sitting at "pending" with a settled
                 * transaction against it is a payment whose webhook never
                 * arrived: the customer was charged, the money is in the bank,
                 * and the system believes they owe us. Treating that as matched
                 * because the reference happened to resolve would bury the one
                 * case reconciliation exists to surface, and would stamp a
                 * settlement onto an order that is not even marked paid.
                 */
                if (! $order || ! in_array($order->state, ['paid', 'partially_refunded', 'refunded'], true)) {
                    $orphans++;
                    $orphanTotal += $transaction->amount();

                    continue;
                }

                $matchedCount++;
                $matchedTotal += $transaction->amount();

                // Stamped on the order so "has this money arrived?" is
                // answerable from the order itself, without joining back
                // through the settlement every time it is asked.
                $order->forceFill([
                    'settlement_id' => $settlement->id,
                    'settled_at'    => $settlement->settlement_date ?? now(),
                    'fees_amount'   => $transaction->fees(),
                    'net_amount'    => round($transaction->amount() - $transaction->fees(), 2),
                ])->save();
            }

            // Refunds processed around this payout. Attributed by date, not by
            // the provider: Paystack reports a deductions total but does not say
            // which refunds make it up, so this is context for the reader rather
            // than a claim about which payout the money came out of.
            Refund::where('state', 'processed')
                ->whereNull('settlement_id')
                ->whereDate('processed_at', '<=', $settlement->settlement_date ?? now())
                ->update(['settlement_id' => $settlement->id]);
        });

        $variance = round((float) $settlement->total_amount - $seenTotal, 2);
        $tolerance = (float) config('agentpro.settlement.variance_tolerance');

        $balanced = abs($variance) <= $tolerance
            && $orphans === 0
            && $settlement->arithmeticHolds();

        $settlement->update([
            'transactions_count'   => count($transactions),
            'matched_count'        => $matchedCount,
            'matched_amount'       => round($matchedTotal, 2),
            'unmatched_count'      => $orphans,
            'unmatched_amount'     => round($orphanTotal, 2),
            'variance'             => $variance,
            'reconciliation_state' => $balanced ? 'balanced' : 'discrepancy',
            'reconciled_at'        => now(),
        ]);

        $report->transactions += count($transactions);
        $report->matched += $matchedCount;
        $report->orphans += $orphans;

        if (! $balanced) {
            $report->discrepancies++;
        }
    }

    /**
     * The reference is what we sent to the provider when the transaction was
     * initialised, so it is the order's uuid — but an order that was paid
     * through a retry can carry a different reference from the provider, which
     * is why both are checked.
     */
    private function matchOrder(SettledTransaction $transaction): ?Order
    {
        if (! $transaction->reference) {
            return null;
        }

        return Order::where('uuid', $transaction->reference)
            ->orWhere('paystack_reference', $transaction->reference)
            ->first();
    }

    /**
     * Ask the provider about every refund still in flight.
     *
     * Cheap, and it closes the gap left by a webhook that never arrived — which
     * for refunds is the difference between a customer who has their money and
     * a support ticket nobody can answer.
     */
    private function pollRefunds(ReconciliationReport $report): void
    {
        $inFlight = Refund::where('state', 'submitted')
            ->whereNotNull('provider_refund_id')
            ->get();

        foreach ($inFlight as $refund) {
            $report->refundsPolled++;

            try {
                $result = $this->gateway->fetchRefund($refund->provider_refund_id);
            } catch (\Throwable $e) {
                $report->problems[] = 'Refund '.$refund->uuid.': '.$e->getMessage();

                continue;
            }

            if ($result && $this->refunds->settle($refund, $result)) {
                $report->refundsResolved++;
            }

            if ($refund->fresh()->isStuck()) {
                $report->problems[] = sprintf(
                    'Refund %s has been with the provider for %d days.',
                    $refund->uuid,
                    (int) $refund->submitted_at->diffInDays(now()),
                );
            }
        }
    }
}
