<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'paid_at'         => 'datetime',
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

    public function isPaid(): bool
    {
        return $this->state === 'paid';
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
