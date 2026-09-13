<?php

namespace App\Actions;

use App\Models\PayoutAccount;
use App\Models\User;
use App\Notifications\PayoutAccountChanged;
use App\Services\Payments\PaymentGateway;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Registering where a lister's money goes (FR-M11-07).
 *
 * Three controls, and they exist because of one specific attack: take over a
 * lister's login, change the bank details, withdraw. It is the highest-value
 * thing anyone can do with a stolen account on this platform, and none of the
 * protections that cover payments help — the session is genuine and the
 * destination is legitimate-looking.
 *
 *  1. The account name comes from the bank, never from the form. A typed name
 *     is a claim; the resolve endpoint is the only fact available.
 *  2. A new or changed account is held for a cooling-off period before it can
 *     receive anything, and the *existing* contact details are told. That turns
 *     a silent theft into something the real owner can stop.
 *  3. If the bank's name does not match the verified identity, a person has to
 *     agree before money moves. Not a refusal — Nigerian agents legitimately
 *     receive into a business account — but not automatic either.
 */
class AddPayoutAccount
{
    public function __construct(private PaymentGateway $gateway) {}

    public function __invoke(User $user, string $accountNumber, string $bankCode): PayoutAccount
    {
        /*
         * Publishing is gated on identity (FR-M1-05) and so is being paid.
         * Without it there is nothing to compare the bank's name against, and
         * the name check is the only automated part of this.
         */
        if (! $user->isVerified()) {
            throw new RuntimeException('Your identity has to be verified before you can add bank details.');
        }

        $resolved = $this->gateway->resolveAccount($accountNumber, $bankCode);

        if (! $resolved || $resolved->accountName === '') {
            throw new RuntimeException(
                'That account number could not be found at that bank. Check both and try again.'
            );
        }

        $matches = $this->namesMatch($resolved->accountName, $user->name);

        $previous = $user->payoutAccounts()->where('is_active', true)->get();

        $account = DB::transaction(function () use ($user, $resolved, $bankCode, $accountNumber, $matches, $previous) {
            // Only one destination at a time. Keeping old rows inactive rather
            // than deleting them means a past payout still points at the account
            // it actually went to.
            $previous->each->update(['is_active' => false]);

            $account = PayoutAccount::updateOrCreate(
                [
                    'user_id'        => $user->id,
                    'bank_code'      => $bankCode,
                    'account_number' => $accountNumber,
                ],
                [
                    'bank_name'             => $resolved->bankName,
                    'account_name'          => $resolved->accountName,
                    'resolved_at'           => now(),
                    'name_matches_identity' => $matches,
                    'is_active'             => true,
                    // The hold. Applied even to the first account, because an
                    // attacker who takes over an account that never had bank
                    // details is in exactly the same position as one who
                    // changes existing ones.
                    'usable_from'           => now()->addHours((int) config('agentpro.payouts.account_hold_hours')),
                    // A previously approved mismatch does not carry over to a
                    // different account number.
                    'approved_by'           => null,
                    'approved_at'           => null,
                ]
            );

            Audit::record('payout_account.changed', $account, [
                'previous' => $previous->map->only(['bank_name', 'account_number'])->all(),
            ], [
                'bank'         => $resolved->bankName,
                'account_name' => $resolved->accountName,
                'last4'        => substr($accountNumber, -4),
                'name_matches' => $matches,
            ], $user->id);

            return $account;
        });

        /*
         * Sent to the details already on file, not to anything supplied with
         * the change. If this is an attacker, the message has to reach the
         * person they are stealing from — which is the entire value of it.
         */
        $user->notify(new PayoutAccountChanged($account));

        return $account;
    }

    /**
     * Does the bank's name look like the verified identity?
     *
     * Deliberately forgiving about ordering and middle names, and deliberately
     * not forgiving about being a different person. Nigerian bank records
     * routinely reorder names and drop or add a middle one, so requiring an
     * exact string match would send almost every legitimate account to manual
     * review and train whoever reviews them to click approve.
     */
    private function namesMatch(string $bankName, string $identityName): bool
    {
        $normalise = fn (string $value) => collect(preg_split('/[^\p{L}]+/u', Str::lower($value), -1, PREG_SPLIT_NO_EMPTY))
            ->reject(fn ($part) => strlen($part) < 2)
            ->sort()
            ->values();

        $bank = $normalise($bankName);
        $identity = $normalise($identityName);

        if ($bank->isEmpty() || $identity->isEmpty()) {
            return false;
        }

        // Every part of the shorter name must appear in the longer one. "ADEYEMI
        // TUNDE" matches "Tunde Adeyemi"; "ADEYEMI PROPERTIES LTD" does not.
        $shorter = $bank->count() <= $identity->count() ? $bank : $identity;
        $longer  = $bank->count() <= $identity->count() ? $identity : $bank;

        return $shorter->every(fn ($part) => $longer->contains($part));
    }
}
