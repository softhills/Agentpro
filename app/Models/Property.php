<?php

namespace App\Models;

use App\Enums\LifecycleState;
use App\Enums\MediaKind;
use App\Support\Vocab;
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
        'closed_at'            => 'datetime',
        'content_updated_at'   => 'datetime',
        'realsure_verified_at' => 'datetime',
        'tags'                 => 'array',
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
     * The public sold-and-let archive.
     *
     * Three conditions, and each one is doing work. Closed says the property
     * found a buyer or a tenant. `closed_at` says it got there through the
     * unlisting flow, so there is a date and an actor on the audit trail behind
     * it — a row that arrived in this state by some other route is not evidence
     * of anything. `published_at` says it was on the market first: without it, a
     * draft could be created and flipped straight to sold, and the one page on
     * this site whose entire purpose is to be believable would be the easiest
     * one to fake.
     */
    public function scopeClosedPublicly(Builder $q): Builder
    {
        return $q->whereIn('lifecycle_state', LifecycleState::closed())
            ->whereNotNull('closed_at')
            ->whereNotNull('published_at');
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

    /**
     * Every tag on this listing, lister-chosen and derived together (FR-M2-04).
     *
     * price_drop is folded in here rather than stored, so the one place that
     * answers "what tags does this listing carry" cannot disagree with the
     * badge on the card — both end up reading Unit::hasPriceDrop().
     *
     * @return list<string>
     */
    public function allTags(): array
    {
        /*
         * Intersected against LISTER_TAGS, not all of TAGS. price_drop is in
         * the vocabulary but is not something anybody may store, so a value
         * written straight into the column — by a seeder, a console command, a
         * future import — is dropped here rather than believed. The form
         * validation is the other layer; this is the one that decides.
         */
        $tags = array_values(array_intersect(
            (array) ($this->tags ?? []),
            Vocab::LISTER_TAGS
        ));

        if ($this->headlineUnit()?->hasPriceDrop()) {
            $tags[] = 'price_drop';
        }

        return $tags;
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->allTags(), true);
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
