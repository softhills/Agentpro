<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money leaving the business to a lister (FR-M11-07).
 *
 * The one movement in the system with a destination somebody chose, which is
 * why it has more states than a refund: a transfer can leave and come back days
 * later, and a reversal has to put money back on a ledger that already spent it.
 */
class Payout extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount'       => 'decimal:2',
        'approved_at'  => 'datetime',
        'submitted_at' => 'datetime',
        'paid_at'      => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class, 'payout_account_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function needsApproval(): bool
    {
        return $this->state === 'requested';
    }

    public function isInFlight(): bool
    {
        return $this->state === 'submitted';
    }

    /** Money is gone from the ledger, whether or not it has landed. */
    public function isCommitted(): bool
    {
        return in_array($this->state, ['requested', 'submitted', 'paid'], true);
    }

    public function isStuck(): bool
    {
        if (! $this->isInFlight() || $this->submitted_at === null) {
            return false;
        }

        return $this->submitted_at->diffInDays(now()) > (int) config('agentpro.payouts.stale_after_days');
    }

    public function stateLabel(): string
    {
        return match ($this->state) {
            'requested' => 'Awaiting approval',
            'submitted' => 'On its way',
            'paid'      => 'Paid',
            'failed'    => 'Failed',
            'reversed'  => 'Returned by the bank',
            'cancelled' => 'Cancelled',
            default     => $this->state,
        };
    }
}
