<?php

namespace App\Models;

use App\Enums\LifecycleState;
use App\Enums\MediaKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Property extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * location holds binary WKB. It is never serialised — leaving it in a JSON
     * response produces invalid UTF-8 and a 500 that is tedious to trace.
     */
    protected $hidden = ['location'];

    protected $casts = [
        'lifecycle_state'      => LifecycleState::class,
        'lat'                  => 'float',
        'lng'                  => 'float',
        'submitted_at'         => 'datetime',
        'published_at'         => 'datetime',
        'expires_at'           => 'datetime',
        'content_updated_at'   => 'datetime',
        'realsure_verified_at' => 'datetime',
    ];

    /** Public URLs key on the uuid, never the sequential id (SEC-10). */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // ---------------------------------------------------------------- relations

    public function lister(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lister_id');
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(MediaAsset::class)->orderBy('sort_order');
    }

    public function titleClaims(): HasMany
    {
        return $this->hasMany(TitleClaim::class);
    }

    public function realsureRecords(): HasMany
    {
        return $this->hasMany(RealsureRecord::class);
    }

    /**
     * Things bought against this listing — a 3D capture, a RealSure engagement.
     *
     * The inverse already existed on Order; this side is what lets a queue ask
     * "has this been paid for and not delivered", which is the only question
     * that should ever sort work to the top.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class)->orderBy('sort_order');
    }

    // ------------------------------------------------------------------- scopes

    /** Anything a guest is allowed to load. */
    public function scopeVisible(Builder $q): Builder
    {
        return $q->whereIn('lifecycle_state', LifecycleState::publiclyVisible());
    }

    /**
     * FR-M2-09: sold and rented are retained and findable, but only when the
     * seeker opts in. They are never in default results.
     */
    public function scopeOnMarket(Builder $q): Builder
    {
        return $q->where('lifecycle_state', LifecycleState::Published->value);
    }

    /**
     * Viewport search. MBRContains is index-accelerated on the SPATIAL index and
     * behaves identically on MySQL 8 and MariaDB, which is why the search pane
     * does not need a separate search cluster (PRD §17).
     */
    public function scopeWithinBounds(Builder $q, float $south, float $west, float $north, float $east): Builder
    {
        $polygon = sprintf(
            'POLYGON((%1$F %2$F, %3$F %2$F, %3$F %4$F, %1$F %4$F, %1$F %2$F))',
            $west, $south, $east, $north
        );

        return $q->whereRaw('MBRContains(ST_GeomFromText(?), location)', [$polygon]);
    }

    /** Radius search in metres. ST_Distance_Sphere exists on both engines. */
    public function scopeWithinMetres(Builder $q, float $lat, float $lng, int $metres): Builder
    {
        return $q->whereRaw(
            'ST_Distance_Sphere(location, ST_GeomFromText(?)) <= ?',
            [sprintf('POINT(%F %F)', $lng, $lat), $metres]
        );
    }

    /** Helper for writes — keeps POINT(lng, lat) ordering in exactly one place. */
    public static function pointExpression(float $lat, float $lng): \Illuminate\Contracts\Database\Query\Expression
    {
        return DB::raw(sprintf("ST_GeomFromText('POINT(%F %F)')", $lng, $lat));
    }

    // --------------------------------------------------------------- attributes

    /** The unit shown on the card — the primary one, else the cheapest available. */
    public function headlineUnit(): ?Unit
    {
        return $this->units->firstWhere('is_primary', true)
            ?? $this->units->where('status', 'available')->sortBy('price')->first()
            ?? $this->units->first();
    }

    public function isRealsureVerified(): bool
    {
        return $this->realsure_verified_at !== null;
    }

    /** FR-M2-14: "New" within a configurable window. */
    public function isFresh(): bool
    {
        $days = (int) config('agentpro.freshness_days', 7);

        return $this->published_at !== null
            && $this->published_at->gt(now()->subDays($days));
    }

    /** FR-M2-10: units badge. Only meaningful for multi-unit developments. */
    public function availableUnitCount(): int
    {
        return $this->units->where('status', 'available')->count();
    }

    public function isMultiUnit(): bool
    {
        return $this->units->count() > 1;
    }

    /**
     * The single media glyph the card is allowed to show.
     * Returns null when the listing has only photographs.
     */
    public function cardGlyph(): ?MediaKind
    {
        $kinds = $this->media
            ->where('moderation_state', 'approved')
            ->pluck('kind')
            ->all();

        foreach (MediaKind::glyphPriority() as $candidate) {
            if (in_array($candidate->value, $kinds, true)) {
                return $candidate;
            }
        }

        return null;
    }

    public function mediaOfKind(MediaKind $kind)
    {
        return $this->media->where('moderation_state', 'approved')->where('kind', $kind->value);
    }

    public function coverImage(): ?MediaAsset
    {
        return $this->media->firstWhere('is_cover', true)
            ?? $this->media->firstWhere('kind', MediaKind::Photo->value);
    }
}
