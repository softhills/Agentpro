<?php

namespace App\Models;

use App\Enums\MediaKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaAsset extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'kind'        => MediaKind::class,
        'renditions'  => 'array',
        'is_cover'    => 'boolean',
        'captured_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** FR-M3-10: provenance line shown beneath the viewer stage. */
    public function provenance(): string
    {
        $who = $this->source === 'agentpro_technician'
            ? 'captured by Agentpro technician'
            : 'uploaded by lister';

        return $this->captured_at
            ? $who.' · '.$this->captured_at->format('j M Y')
            : $who;
    }

    public function durationLabel(): ?string
    {
        if (! $this->duration_seconds) {
            return null;
        }

        return sprintf('%d:%02d', intdiv($this->duration_seconds, 60), $this->duration_seconds % 60);
    }
}
