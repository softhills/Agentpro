<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line inside a settlement (FR-M11-06). */
class SettlementTransaction extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount'  => 'decimal:2',
        'fees'    => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Money arrived and no order on this system carries that reference at all. */
    public function isOrphan(): bool
    {
        return $this->order_id === null;
    }

    /**
     * The order exists and is not marked paid.
     *
     * A payment whose webhook never landed: the customer was charged, the money
     * is in the bank, and the system believes they still owe us. Unlike a true
     * orphan this one is recoverable in a click, because we know exactly which
     * order it belongs to.
     */
    public function isUnconfirmed(): bool
    {
        return $this->order !== null
            && ! in_array($this->order->state, ['paid', 'partially_refunded', 'refunded'], true);
    }

    /** Either kind of money we cannot account for. */
    public function needsAttention(): bool
    {
        return $this->isOrphan() || $this->isUnconfirmed();
    }
}
