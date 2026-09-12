<?php

namespace App\Models;

use App\Support\Vocab;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A title the lister declares (FR-M6-04).
 *
 * "Declared" is the operative word — nothing here is an assertion by Agentpro
 * unless the matching RealSure title_verification component is complete.
 */
class TitleClaim extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['declared_at' => 'datetime'];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function label(): string
    {
        return Vocab::titleTypeLabel($this->title_type);
    }

    public function isAvailable(): bool
    {
        return $this->stage === 'available';
    }
}
