<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of the move-in cost breakdown (M7). */
class FeeLine extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'amount'          => 'decimal:2',
        'percentage_rate' => 'decimal:2',
        'is_refundable'   => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** FR-M7-04: percentage lines show their rate, e.g. "Agency fee 10%". */
    public function rateNote(): ?string
    {
        return $this->calc_type === 'percentage' && $this->percentage_rate !== null
            ? rtrim(rtrim(number_format((float) $this->percentage_rate, 2, '.', ''), '0'), '.').'%'
            : null;
    }
}
