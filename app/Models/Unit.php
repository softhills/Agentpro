<?php

namespace App\Models;

use App\Enums\PricePeriod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lettable or saleable unit within a property (FR-M2-13).
 *
 * A single dwelling is a property with exactly one unit, so every query and
 * every view has one shape whether it is a standalone duplex or flat 3B in a
 * block of twelve.
 */
class Unit extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'price'          => 'decimal:2',
        'price_period'   => PricePeriod::class,
        'available_from' => 'date',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function feeLines(): HasMany
    {
        return $this->hasMany(FeeLine::class)->orderBy('sort_order');
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(PriceHistory::class)->orderByDesc('effective_at');
    }

    // ------------------------------------------------------------------- money

    /**
     * FR-M7-02: the number that actually matters — what a tenant must find to
     * move in. Rent is included because the advertised figure is only ever a
     * component of it.
     */
    public function moveInTotal(): float
    {
        return (float) $this->price + $this->feeLines->sum('amount');
    }

    /** Of that total, how much comes back at the end of the tenancy. */
    public function refundableTotal(): float
    {
        return (float) $this->feeLines->where('is_refundable', true)->sum('amount');
    }

    public function nonRefundableTotal(): float
    {
        return $this->moveInTotal() - $this->refundableTotal();
    }

    /**
     * FR-M7-01: publishing is blocked until the breakdown is complete. A rental
     * with no fee lines is almost always an omission rather than a genuinely
     * fee-free let, so it is treated as incomplete.
     */
    public function hasCompleteFeeBreakdown(): bool
    {
        return $this->feeLines->isNotEmpty();
    }

    /** FR-M2-04 / FR-M7-07: derived from history, never chosen by the lister. */
    public function previousPrice(): ?float
    {
        $previous = $this->priceHistory
            ->firstWhere(fn ($h) => (float) $h->price !== (float) $this->price);

        return $previous ? (float) $previous->price : null;
    }

    public function hasPriceDrop(): bool
    {
        $previous = $this->previousPrice();

        return $previous !== null && $previous > (float) $this->price;
    }
}
