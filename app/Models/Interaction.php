<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A seeker's standing relationship with a listing (FR-M9-04).
 *
 * Saving, rating, reporting or contacting all count as interest and therefore
 * as consent to be told when the listing changes. Viewing does not, and there
 * is deliberately no 'view' kind here.
 */
class Interaction extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['rating' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** Kinds that make someone an audience for listing alerts. */
    public const ALERTING = ['save', 'rate', 'report', 'contact'];
}
