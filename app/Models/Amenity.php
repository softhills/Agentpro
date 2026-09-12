<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** FR-M5-09: the Nigerian amenity set. */
class Amenity extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['is_filterable' => 'boolean'];

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class);
    }
}
