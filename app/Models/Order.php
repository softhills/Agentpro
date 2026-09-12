<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A payable item (M11).
 *
 * The amount is written once, server-side, from versioned configuration. The
 * client never sends a price and the order never takes one from a request
 * (SEC-05) — `price_version` records which price list the charge was made
 * under, so a change next quarter does not rewrite the history of what someone
 * actually paid.
 */
class Order extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'amount'          => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'fees_amount'     => 'decimal:2',
        'net_amount'      => 'decimal:2',
        'paid_at'         => 'datetime',
        'settled_at'      => 'datetime',
        'receipt_sent_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function scanJob(): HasOne
    {
        return $this->hasOne(ScanJob::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function isPaid(): bool
    {
        return $this->state === 'paid';
    }

    /**
     * What can still be refunded.
     *
     * Counts refunds that are merely *requested* as already spoken for. Ignoring
     * them lets two operators each refund the full amount while the first one is
     * still waiting for approval, and the provider will happily process both.
     */
    public function refundableAmount(): float
    {
        $committed = (float) $this->refunds()
            ->whereIn('state', ['requested', 'submitted', 'processed'])
            ->sum('amount');

        return max(0, round((float) $this->amount - $committed, 2));
    }

    /**
     * Recalculate the denormalised total from the refunds that actually landed.
     *
     * A requested or in-flight refund deliberately does not move the order's
     * state: the customer has not been paid back yet, and an order that says
     * "refunded" before the money moves is a lie told to whoever reads it next.
     */
    public function syncRefundState(): void
    {
        $processed = (float) $this->refunds()->where('state', 'processed')->sum('amount');
        $latest    = $this->refunds()->where('state', 'processed')->latest('processed_at')->first();

        $this->forceFill([
            'refunded_amount' => $processed,
            'refund_reason'   => $latest?->reason ?? $this->refund_reason,
            'state'           => match (true) {
                $processed <= 0                        => in_array($this->state, ['refunded', 'partially_refunded'], true) ? 'paid' : $this->state,
                $processed >= (float) $this->amount - 0.009 => 'refunded',
                default                                => 'partially_refunded',
            },
        ])->save();
    }

    /** Has the money for this order actually reached the bank? */
    public function isSettled(): bool
    {
        return $this->settlement_id !== null;
    }

    /**
     * FR-M4-07: a paid order with no scan job is the dangerous state — the
     * lister has been charged and has nothing. It is recoverable, not a dead
     * end, and this is the predicate that surfaces it.
     */
    public function isUnredeemed(): bool
    {
        return $this->item_type === 'scan_3d'
            && $this->isPaid()
            && $this->scanJob()->doesntExist();
    }

    public function itemLabel(): string
    {
        return match ($this->item_type) {
            'scan_3d'      => '3D tour capture',
            'realsure'     => 'RealSure verification',
            'boost'        => 'Featured placement',
            'subscription' => 'Subscription',
            default        => $this->item_type,
        };
    }
}
