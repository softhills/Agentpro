<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payout from the provider into the business bank account (FR-M11-06).
 *
 * The row carries both sides of the comparison: what the provider says it paid
 * out, and what this system can account for. Keeping them side by side is the
 * whole point — a settlement total on its own is just a number from somebody
 * else's system.
 */
class Settlement extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'settlement_date'     => 'date',
        'provider_created_at' => 'datetime',
        'reconciled_at'       => 'datetime',
        'total_amount'        => 'decimal:2',
        'total_fees'          => 'decimal:2',
        'deductions'          => 'decimal:2',
        'effective_amount'    => 'decimal:2',
        'matched_amount'      => 'decimal:2',
        'unmatched_amount'    => 'decimal:2',
        'variance'            => 'decimal:2',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(SettlementTransaction::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isBalanced(): bool
    {
        return $this->reconciliation_state === 'balanced';
    }

    /**
     * Does the provider's own arithmetic hold?
     *
     * total − fees − deductions should be what reaches the bank. If it does not,
     * either a field was misread on the way in or the payout was not what the
     * summary claims; both need a person before any of the other figures on this
     * row are worth reading.
     */
    public function arithmeticHolds(): bool
    {
        $expected = (float) $this->total_amount - (float) $this->total_fees - (float) $this->deductions;

        return abs($expected - (float) $this->effective_amount)
            <= (float) config('agentpro.settlement.variance_tolerance');
    }

    /** The share of the payout this system can point at an order for. */
    public function explainedShare(): ?int
    {
        if ((float) $this->total_amount <= 0) {
            return null;
        }

        return (int) round((float) $this->matched_amount / (float) $this->total_amount * 100);
    }

    public function statusLabel(): string
    {
        return match (strtolower((string) $this->status)) {
            'success'    => 'Paid out',
            'processing' => 'Processing',
            'pending'    => 'Pending',
            'failed'     => 'Failed',
            default      => (string) $this->status,
        };
    }
}
