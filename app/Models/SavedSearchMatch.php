<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One listing, reported once to one saved search.
 *
 * A row with a null notified_at is a baseline entry: it matched when the search
 * was created, so it is recorded as seen without ever having been sent.
 */
class SavedSearchMatch extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['notified_at' => 'datetime'];

    public function savedSearch(): BelongsTo
    {
        return $this->belongsTo(SavedSearch::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
