<?php

namespace App\Models;

use App\Enums\MediaKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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

    /**
     * URL for a stored rendition. Returns null when nothing has been uploaded
     * yet, which is the signal for the views to fall back to placeholder art
     * rather than render a broken image.
     */
    public function url(string $rendition = '800'): ?string
    {
        $path = $this->renditions[$rendition] ?? $this->path;

        if (! $path || ! $this->disk) {
            return null;
        }

        return Storage::disk($this->disk)->url($path);
    }

    public function posterUrl(): ?string
    {
        return $this->poster_path && $this->disk
            ? Storage::disk($this->disk)->url($this->poster_path)
            : null;
    }

    /**
     * srcset for the responsive photograph set (NFR-02) — the browser picks the
     * width it actually needs instead of always taking the largest.
     */
    public function srcset(): ?string
    {
        $set = [];

        foreach (($this->renditions ?? []) as $key => $path) {
            if (is_numeric($key)) {
                $set[] = Storage::disk($this->disk)->url($path).' '.$key.'w';
            }
        }

        return $set ? implode(', ', $set) : null;
    }

    /** Video is visible on the listing only once a moderator has cleared it. */
    public function isApproved(): bool
    {
        return $this->moderation_state === 'approved';
    }
}
