<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Area extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'is_scan_coverage' => 'boolean',
        'centroid_lat'     => 'float',
        'centroid_lng'     => 'float',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /** FR-M4-02: 3D capture is only offered inside an active coverage area. */
    public function scopeScanCoverage($q)
    {
        return $q->where('is_scan_coverage', true);
    }
}
