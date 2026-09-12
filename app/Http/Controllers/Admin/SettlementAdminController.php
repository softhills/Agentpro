<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ConfirmPayment;
use App\Actions\ReconcileSettlements;
use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Models\Settlement;
use App\Models\SettlementTransaction;
use Illuminate\Http\Request;

/**
 * Settlement reconciliation (FR-M11-06).
 *
 * The screen Finance is actually asked to answer questions from: not "what did
 * we charge", which the orders list already shows, but "what arrived in the
 * bank, and can we account for all of it".
 */
class SettlementAdminController extends Controller
{
    public function index(ReconcileSettlements $reconciler)
    {
        return view('admin.settlements', [
            'settlements' => Settlement::orderByDesc('settlement_date')
                ->orderByDesc('id')
                ->paginate(25),
            'totals' => [
                'settled_30d' => (float) Settlement::where('settlement_date', '>=', now()->subDays(30))
                    ->where('status', 'success')->sum('effective_amount'),
                'fees_30d' => (float) Settlement::where('settlement_date', '>=', now()->subDays(30))
                    ->where('status', 'success')->sum('total_fees'),
                'discrepancies' => Settlement::where('reconciliation_state', 'discrepancy')->count(),
                'unreconciled'  => Settlement::where('reconciliation_state', 'unreconciled')->count(),
                // Taken from the reconciliation rollup rather than recounted
                // here, so the screen cannot quietly disagree with the run that
                // produced it.
                'orphans'       => (int) Settlement::sum('unmatched_count'),
                'orphan_amount' => (float) Settlement::sum('unmatched_amount'),
            ],
            'unsettled' => $reconciler->unsettledOrders()->with('user:id,name')->limit(25)->get(),
            'unsettledCount' => $reconciler->unsettledOrders()->count(),
            'stuckRefunds' => Refund::where('state', 'submitted')
                ->with('order:id,uuid,amount')
                ->oldest('submitted_at')->get()
                ->filter(fn (Refund $r) => $r->isStuck())->values(),
        ]);
    }

    public function show(Settlement $settlement)
    {
        return view('admin.settlement', [
            'settlement' => $settlement,
            // Unaccounted money first: it is the reason to open this page.
            'transactions' => $settlement->transactions()
                ->with(['order:id,uuid,item_type,amount,state,user_id', 'order.user:id,name'])
                ->orderByDesc('amount')
                ->get()
                ->sortByDesc(fn ($t) => $t->needsAttention() ? 1 : 0)
                ->values(),
            'refunds' => Refund::where('settlement_id', $settlement->id)
                ->with('order:id,uuid')->get(),
        ]);
    }

    /**
     * Recover a payment that settled but was never confirmed.
     *
     * Deliberately a button rather than something the nightly job does on its
     * own. The evidence is strong — the money is in the bank — but confirming
     * an order has consequences beyond the ledger (it grants the entitlement and
     * notifies the customer), and those should be set in motion by a person who
     * looked at the row, with their name on the audit entry. It still goes
     * through the normal verification path, so the amount is checked against the
     * order exactly as it would be for a webhook.
     */
    public function confirmTransaction(SettlementTransaction $transaction, Request $request, ConfirmPayment $payments)
    {
        if (! $transaction->needsAttention()) {
            return back()->withErrors(['transaction' => 'That transaction is already matched to a paid order.']);
        }

        if (! $transaction->reference) {
            return back()->withErrors(['transaction' => 'That transaction carries no reference, so there is nothing to match it to. It has to be traced in the provider dashboard.']);
        }

        $changed = $payments->confirm($transaction->reference, actorId: $request->user()->id);

        if (! $changed) {
            return back()->withErrors(['transaction' =>
                'The provider would not confirm that reference, or it does not belong to an unpaid order here. Nothing was changed.',
            ]);
        }

        return back()->with('status', 'Order confirmed from the settlement. Re-run reconciliation to match the row.');
    }

    /** Run it now, rather than waiting for tomorrow's 06:30. */
    public function reconcile(ReconcileSettlements $reconciler)
    {
        $report = $reconciler->run();

        return back()->with('status', 'Reconciled: '.$report->summary());
    }
}
