<?php

namespace App\Support;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What the business owes a lister (FR-M11-07).
 *
 * A balance is derived, never stored. The alternative — a `balance` column kept
 * in step by whoever remembers — fails in one specific way that is very
 * expensive here: it drifts, and there is no way afterwards to say which of the
 * two numbers was right or where the difference came from. Summing entries is
 * slower and always explainable, and this is read on a dashboard, not in a loop.
 *
 * Entries are written here and nowhere else, so "money appeared on a ledger
 * without a reason and an author" is not a state the system can reach.
 */
final class Ledger
{
    /** Everything owed, including money already held for a pending payout. */
    public static function balanceFor(User|int $user): float
    {
        $id = $user instanceof User ? $user->id : $user;

        $sums = LedgerEntry::where('user_id', $id)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0) AS credits")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END), 0) AS debits")
            ->first();

        return round((float) $sums->credits - (float) $sums->debits, 2);
    }

    /**
     * Record an entry.
     *
     * Deliberately not a model method: writing to a ledger is an event with an
     * author and a reason, and `LedgerEntry::create()` scattered through
     * controllers would make both optional.
     */
    public static function record(
        User|int $user,
        string $direction,
        float $amount,
        string $kind,
        string $memo,
        ?int $payoutId = null,
        ?int $authorId = null,
    ): LedgerEntry {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('A ledger entry must be for a positive amount; direction carries the sign.');
        }

        return LedgerEntry::create([
            'user_id'    => $user instanceof User ? $user->id : $user,
            'direction'  => $direction,
            'amount'     => round($amount, 2),
            'currency'   => 'NGN',
            'kind'       => $kind,
            'memo'       => $memo,
            'payout_id'  => $payoutId,
            'created_by' => $authorId ?? auth()->id(),
        ]);
    }

    /**
     * Everyone with money owed, for the admin list.
     *
     * A single grouped query rather than a balance per user in a loop: the
     * obvious implementation is an N+1 that only shows up once the programme
     * has a few hundred participants.
     */
    public static function balances(float $minimum = 0.01)
    {
        return LedgerEntry::query()
            ->select('user_id')
            ->selectRaw("SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) AS balance")
            ->groupBy('user_id')
            ->havingRaw('SUM(CASE WHEN direction = ? THEN amount ELSE -amount END) >= ?', ['credit', $minimum])
            ->orderByDesc('balance');
    }

    /** The whole obligation, for the dashboard. */
    public static function totalOwed(): float
    {
        return round((float) DB::table('ledger_entries')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) AS total")
            ->value('total'), 2);
    }
}
