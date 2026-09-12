<?php

namespace App\Models;

use App\Enums\PricePeriod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceHistory extends Model
{
    use HasFactory;

    protected $table = 'price_histories';

    protected $guarded = ['id'];

    protected $casts = [
        'price'        => 'decimal:2',
        'price_period' => PricePeriod::class,
        'effective_at' => 'datetime',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
