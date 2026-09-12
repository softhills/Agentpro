<?php

namespace App\Models;

use App\Support\Vocab;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One verification component against one property (FR-M6-02).
 *
 * An incomplete record is displayed, not hidden: knowing that no valuation was
 * commissioned is as useful to a buyer as knowing the title was checked.
 */
class RealsureRecord extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'completed'    => 'boolean',
        'completed_on' => 'date',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'officer_id');
    }

    public function label(): string
    {
        return Vocab::REALSURE_COMPONENTS[$this->component] ?? $this->component;
    }
}
