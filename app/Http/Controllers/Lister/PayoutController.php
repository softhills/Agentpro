<?php

namespace App\Http\Controllers\Lister;

use App\Actions\AddPayoutAccount;
use App\Actions\IssuePayout;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Services\Payments\PaymentGateway;
use App\Support\Ledger;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * What a lister is owed, and where it goes (FR-M11-07).
 *
 * The balance is shown as a running statement rather than a single figure. A
 * number on its own invites a dispute nobody can settle; a list of entries with
 * a reason and a date against each answers it.
 */
class PayoutController extends Controller
{
    public function index(Request $request, PaymentGateway $gateway)
    {
        $user = $request->user();

        return view('lister.payouts', [
            'balance' => Ledger::balanceFor($user),
            'entries' => LedgerEntry::where('user_id', $user->id)
                ->with('payout:id,uuid,state')
                ->latest('id')->limit(50)->get(),
            'account' => $user->activePayoutAccount(),
            'payouts' => Payout::where('user_id', $user->id)
                ->with('account:id,bank_name,account_number')
                ->latest('id')->limit(20)->get(),
            'banks'   => $gateway->banks(),
            'minimum' => (float) config('agentpro.payouts.minimum'),
        ]);
    }

    /**
     * Add or change where money is sent.
     *
     * The account name is never taken from this form — it comes from the bank —
     * so there is nothing here to type it into. See App\Actions\AddPayoutAccount.
     */
    public function storeAccount(Request $request, AddPayoutAccount $add)
    {
        $data = $request->validate([
            'account_number' => ['required', 'digits:10'],
            'bank_code'      => ['required', 'string', 'max:10'],
        ], [
            'account_number.digits' => 'A Nigerian account number is ten digits.',
        ]);

        try {
            $account = $add($request->user(), $data['account_number'], $data['bank_code']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['account_number' => $e->getMessage()])->withInput();
        }

        return back()->with('status', sprintf(
            'Saved: %s at %s. Nothing can be sent to it until %s — a security hold, so that if this was not you there is time to say so.',
            $account->account_name,
            $account->bank_name,
            $account->usable_from->format('j M, g:ia'),
        ));
    }

    /**
     * Ask to be paid.
     *
     * The lister requests; an admin approves. Self-service both ways would make
     * a stolen login worth the balance.
     */
    public function requestPayout(Request $request, IssuePayout $payouts)
    {
        $user = $request->user();

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:99999999999'],
        ]);

        try {
            $payout = $payouts->request($user, (float) $data['amount'], $user);
        } catch (RuntimeException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        return back()->with('status', sprintf(
            'Requested. %s is being held for this payout while it is checked.',
            \App\Support\Money::naira($payout->amount),
        ));
    }
}
