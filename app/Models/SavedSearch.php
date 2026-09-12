<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A search someone asked to be told about (FR-M5-07).
 *
 * Promoted to R1 because it is the loop that brings a seeker back before they
 * have found anything — see PRD §16. Everything else on the platform requires
 * the seeker to think of us first.
 */
class SavedSearch extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'criteria'    => 'array',
        'bounds'      => 'array',
        'last_run_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(SavedSearchMatch::class);
    }

    public function isActive(): bool
    {
        return $this->frequency !== 'off';
    }

    public function frequencyLabel(): string
    {
        return match ($this->frequency) {
            'instant' => 'As soon as something matches',
            'daily'   => 'Once a day',
            default   => 'Paused',
        };
    }

    /**
     * A readable account of what was saved.
     *
     * Written from the criteria rather than stored as a string, so renaming a
     * filter or changing a price band does not leave stale descriptions behind.
     */
    public function describe(): string
    {
        $c = $this->criteria ?? [];
        $parts = [];

        if (! empty($c['beds'])) {
            $parts[] = $c['beds'].'+ bed';
        }

        $parts[] = match ($c['type'] ?? null) {
            'apartment' => 'apartments',
            'house'     => 'houses',
            'land'      => 'land',
            default     => 'properties',
        };

        if (! empty($c['intent'])) {
            $parts[] = $c['intent'] === 'sale' ? 'for sale' : 'to rent';
        }

        if (! empty($c['q'])) {
            $parts[] = 'in '.$c['q'];
        }

        if (! empty($c['max_price'])) {
            $parts[] = 'under '.Money::naira($c['max_price'], compact: true);
        }

        if (! empty($c['realsure'])) {
            $parts[] = '· RealSure only';
        }

        if (! empty($c['amenities'])) {
            $parts[] = '· '.count($c['amenities']).' '.str('amenity')->plural(count($c['amenities']));
        }

        return ucfirst(implode(' ', $parts));
    }
}
