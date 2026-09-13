<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of what the business owes a lister (FR-M11-07).
 *
 * Append-only. Nothing updates a row here and nothing deletes one; a correction
 * is another entry in the opposite direction. That is what makes a balance
 * explainable line by line — and a balance that cannot be explained is one
 * nobody can defend when a lister disputes it.
 */
class LedgerEntry extends Model
{
    protected $guarded = ['id'];

    /** The table has no updated_at, because nothing is ever updated. */
    public const UPDATED_AT = null;

    protected $casts = [
        'amount'     => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Signed, for running totals. */
    public function signedAmount(): float
    {
        return $this->direction === 'credit' ? (float) $this->amount : -(float) $this->amount;
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            'listing_incentive' => 'Listing incentive',
            'referral'          => 'Referral',
            'service_credit'    => 'Service credit',
            'correction'        => 'Correction',
            'payout'            => 'Paid out',
            'payout_returned'   => 'Payout returned',
            default             => $this->kind,
        };
    }
}
