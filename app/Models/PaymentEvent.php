<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A received webhook (SEC-05).
 *
 * The provider's own event id is unique here, which is what makes webhook
 * handling idempotent: payment providers retry, and a replayed event must not
 * credit an order twice.
 */
class PaymentEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'payload'          => 'array',
        'signature_valid'  => 'boolean',
        'processed_at'     => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
