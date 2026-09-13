<?php

namespace App\Http\Controllers\Admin;

use App\Actions\IssuePayout;
use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use App\Support\Audit;
use App\Support\Ledger;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Approving and sending payouts (FR-M11-07).
 *
 * Admin-only, like refunds and settlements — and stricter than either, because
 * this is the one screen where money can be sent somewhere it has never been.
 */
class PayoutAdminController extends Controller
{
    public function index(Request $request, PaymentGateway $gateway)
    {
        return view('admin.payouts', [
            'pending' => Payout::where('state', 'requested')
                ->with(['user:id,name,email', 'account', 'requester:id,name'])
                ->oldest('id')->get(),
            'recent' => Payout::whereIn('state', ['submitted', 'paid', 'failed', 'reversed', 'cancelled'])
                ->with(['user:id,name', 'account:id,bank_name,account_number'])
                ->latest('id')->limit(30)->get(),
            'owed' => Ledger::balances()
                ->with('user:id,name,email,verification_state')
                ->limit(50)->get(),
            'totals' => [
                'owed'      => Ledger::totalOwed(),
                'held'      => (float) Payout::whereIn('state', ['requested', 'submitted'])->sum('amount'),
                'paid_30d'  => (float) Payout::where('state', 'paid')
                    ->where('paid_at', '>=', now()->subDays(30))->sum('amount'),
                'stuck'     => Payout::where('state', 'submitted')
                    ->where('submitted_at', '<', now()->subDays((int) config('agentpro.payouts.stale_after_days')))
                    ->count(),
                // Read live: approving a payout the float cannot cover fails at
                // the provider, and it is better to see that before pressing it.
                'balance'   => $gateway->balance(),
            ],
            // Accounts whose bank name does not match the verified identity.
            // Not wrong — Nigerian agents legitimately use business accounts —
            // but not automatic either.
            'unmatched' => PayoutAccount::where('is_active', true)
                ->where('name_matches_identity', false)
                ->whereNull('approved_at')
                ->with('user:id,name,verification_state')
                ->get(),
        ]);
    }

    /** Credit a lister. The only thing that puts money on a ledger by hand. */
    public function credit(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'amount'  => ['required', 'numeric', 'min:1', 'max:99999999'],
            'kind'    => ['required', 'in:listing_incentive,referral,service_credit,correction'],
            'memo'    => ['required', 'string', 'min:5', 'max:200'],
        ], [
            'memo.min' => 'Say what this is for — it is what a lister sees on their statement.',
        ]);

        $lister = User::findOrFail($data['user_id']);

        $entry = Ledger::record(
            $lister,
            'credit',
            (float) $data['amount'],
            $data['kind'],
            $data['memo'],
            null,
            $request->user()->id,
        );

        Audit::record('ledger.credited', $lister, [], [
            'amount' => (float) $data['amount'],
            'kind'   => $data['kind'],
            'memo'   => $data['memo'],
        ]);

        return back()->with('status', sprintf(
            '%s credited to %s.',
            \App\Support\Money::naira($entry->amount),
            $lister->name,
        ));
    }

    public function approve(Payout $payout, Request $request, IssuePayout $payouts)
    {
        try {
            $payouts->approve($payout, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['payout' => $e->getMessage()]);
        }

        return back()->with('status', 'Approved and sent. It will show as paid once the bank confirms.');
    }

    public function cancel(Payout $payout, Request $request, IssuePayout $payouts)
    {
        $data = $request->validate(['why' => ['required', 'string', 'min:5', 'max:200']]);

        try {
            $payouts->cancel($payout, $request->user(), $data['why']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['payout' => $e->getMessage()]);
        }

        return back()->with('status', 'Cancelled. The money is back on the lister\'s balance.');
    }

    /**
     * Put the money back after a submission failure whose fate is now known.
     *
     * Deliberately manual: a transfer that timed out may have succeeded, so
     * crediting automatically could pay the same money twice.
     */
    public function returnToLedger(Payout $payout, Request $request, IssuePayout $payouts)
    {
        $data = $request->validate([
            'why' => ['required', 'string', 'min:10', 'max:200'],
        ], [
            'why.min' => 'Record how you established the transfer did not go out.',
        ]);

        try {
            $payouts->returnToLedger($payout, $request->user(), $data['why']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['payout' => $e->getMessage()]);
        }

        return back()->with('status', 'Returned to the lister\'s balance.');
    }

    /** Agree that a business account belongs to this lister. */
    public function approveAccount(PayoutAccount $account, Request $request)
    {
        $account->update(['approved_by' => $request->user()->id, 'approved_at' => now()]);

        Audit::record('payout_account.approved', $account, ['name_matches_identity' => false], [
            'account_name' => $account->account_name,
            'lister'       => $account->user?->name,
        ]);

        return back()->with('status', 'Approved. Payouts to this account can now be sent.');
    }
}
