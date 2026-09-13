<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sent (or attempted) SMS, kept because every one of them costs money. */
class SmsMessage extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'transactional' => 'boolean',
        'cost'          => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
