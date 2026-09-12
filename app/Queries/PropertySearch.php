<?php

namespace App\Queries;

use App\Enums\LifecycleState;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The search query (M5).
 *
 * Everything the map-first search needs, in one object, so there is exactly one
 * place where "which listings can this person see" is decided. The search page,
 * the map pin endpoint, the sitemap and the saved-search matcher all come
 * through here — if they diverged, a listing could appear on the map and 404 on
 * click, or stay visible to alerts after being unpublished.
 *
 * Two rules are load-bearing:
 *  - Filters run against indexed columns. The bounds filter uses MBRContains on
 *    the SPATIAL index rather than BETWEEN on lat/lng, which is what keeps this
 *    affordable without a search cluster (PRD §17).
 *  - Nothing here interpolates user input into SQL. Bounds are cast to float and
 *    bound as parameters (SEC-01).
 */
class PropertySearch
{
    private array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public static function fromRequest(Request $request): self
    {
        return new self($request->validate([
            'q'          => ['nullable', 'string', 'max:120'],
            'intent'     => ['nullable', 'in:rent,sale'],
            'type'       => ['nullable', 'in:land,house,apartment'],
            'area'       => ['nullable', 'string', 'max:80'],
            'min_price'  => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'max_price'  => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'beds'       => ['nullable', 'integer', 'min:0', 'max:20'],
            'baths'      => ['nullable', 'integer', 'min:0', 'max:20'],
            'finish'     => ['nullable', 'in:furnished,unfurnished,core,carcass'],
            'realsure'   => ['nullable', 'boolean'],
            'has_3d'     => ['nullable', 'boolean'],
            'has_video'  => ['nullable', 'boolean'],
            'amenities'  => ['nullable', 'array', 'max:20'],
            'amenities.*'=> ['string', 'max:60'],
            'include_closed' => ['nullable', 'boolean'],
            'sort'       => ['nullable', 'in:newest,price_asc,price_desc,relevance'],
            // Map viewport
            'south' => ['nullable', 'numeric', 'between:-90,90'],
            'north' => ['nullable', 'numeric', 'between:-90,90'],
            'west'  => ['nullable', 'numeric', 'between:-180,180'],
            'east'  => ['nullable', 'numeric', 'between:-180,180'],
            // Decides whether the map gets individual pins or clusters.
            'zoom'  => ['nullable', 'integer', 'between:1,22'],
        ]));
    }

    /**
     * The validated criteria, with empties removed.
     *
     * Saving a search stores what comes out of here rather than the raw query
     * string, so a saved search cannot contain a filter the search itself would
     * reject, and the two definitions cannot drift apart.
     *
     * @return array<string,mixed>
     */
    public function filters(): array
    {
        return array_filter(
            $this->filters,
            fn ($value) => $value !== null && $value !== '' && $value !== []
        );
    }

    public function builder(): Builder
    {
        $f = $this->filters;

        $query = Property::query()
            ->with([
                'units' => fn ($q) => $q->orderBy('price'),
                'units.feeLines',
                'media',
                'area',
                'lister:id,name,verification_state',
            ]);

        // FR-M2-09: closed listings stay findable, but only on request.
        if (! empty($f['include_closed'])) {
            $query->visible();
        } else {
            $query->onMarket();
        }

        // Viewport. Index-accelerated; see the scope on Property.
        if (isset($f['south'], $f['west'], $f['north'], $f['east'])) {
            $query->withinBounds(
                (float) $f['south'],
                (float) $f['west'],
                (float) $f['north'],
                (float) $f['east'],
            );
        }

        if (! empty($f['q'])) {
            // Tolerant of the spelling variants this market actually types
            // (FR-M5-01). FULLTEXT handles the rest.
            $term = trim($f['q']);
            $query->where(function (Builder $sub) use ($term) {
                $sub->whereFullText(['title', 'description', 'address_line'], $term)
                    ->orWhere('address_line', 'like', '%'.$term.'%')
                    ->orWhere('title', 'like', '%'.$term.'%');
            });
        }

        if (! empty($f['intent'])) {
            $query->where('intent', $f['intent']);
        }

        if (! empty($f['type'])) {
            $query->where('listing_type', $f['type']);
        }

        if (! empty($f['finish'])) {
            $query->where('finish', $f['finish']);
        }

        if (! empty($f['area'])) {
            $query->whereHas('area', fn ($q) => $q->where('slug', $f['area']));
        }

        // FR-M6-01: the RealSure filter.
        if (! empty($f['realsure'])) {
            $query->whereNotNull('realsure_verified_at');
        }

        if (! empty($f['has_3d'])) {
            $query->whereHas('media', fn ($q) => $q->where('kind', 'tour_3d')->where('moderation_state', 'approved'));
        }

        if (! empty($f['has_video'])) {
            $query->whereHas('media', fn ($q) => $q->where('kind', 'video')->where('moderation_state', 'approved'));
        }

        // FR-M5-09: every requested amenity must be present, not merely one of them.
        if (! empty($f['amenities'])) {
            foreach ($f['amenities'] as $slug) {
                $query->whereHas('amenities', fn ($q) => $q->where('slug', $slug));
            }
        }

        // Price and room filters apply to the unit, not the property — a block
        // with one affordable flat should surface on a budget search.
        $unitFilters = array_filter([
            'min_price' => $f['min_price'] ?? null,
            'max_price' => $f['max_price'] ?? null,
            'beds'      => $f['beds'] ?? null,
            'baths'     => $f['baths'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($unitFilters !== []) {
            $query->whereHas('units', function ($q) use ($unitFilters) {
                $q->where('status', 'available');

                if (isset($unitFilters['min_price'])) {
                    $q->where('price', '>=', $unitFilters['min_price']);
                }
                if (isset($unitFilters['max_price'])) {
                    $q->where('price', '<=', $unitFilters['max_price']);
                }
                if (isset($unitFilters['beds'])) {
                    $q->where('bedrooms', '>=', $unitFilters['beds']);
                }
                if (isset($unitFilters['baths'])) {
                    $q->where('bathrooms', '>=', $unitFilters['baths']);
                }
            });
        }

        return $this->applySort($query, $f['sort'] ?? 'newest');
    }

    private function applySort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            // Cheapest available unit first. The subquery is correlated but
            // indexed on (property_id, status).
            'price_asc' => $query->orderBy(
                \App\Models\Unit::select('price')
                    ->whereColumn('units.property_id', 'properties.id')
                    ->where('status', 'available')
                    ->orderBy('price')
                    ->limit(1)
            ),
            'price_desc' => $query->orderByDesc(
                \App\Models\Unit::select('price')
                    ->whereColumn('units.property_id', 'properties.id')
                    ->where('status', 'available')
                    ->orderByDesc('price')
                    ->limit(1)
            ),
            // Verified stock first, then freshness — the ordering that makes the
            // trust proposition visible rather than merely available.
            'relevance' => $query->orderByRaw('realsure_verified_at IS NULL')
                                 ->orderByDesc('published_at'),
            default => $query->orderByDesc('published_at'),
        };
    }

    /**
     * Aggregated map markers for a zoomed-out viewport (FR-M5-02, PRD §17).
     *
     * Grouping happens in SQL, so a viewport covering the whole of Lagos returns
     * a few dozen cluster rows rather than several thousand listings the browser
     * would then have to cluster itself. That is the difference between the map
     * being usable on a mid-range Android over 4G and not.
     *
     * The grid is derived from zoom: each step halves the cell, which keeps
     * cluster counts roughly stable as the seeker zooms rather than collapsing
     * everything into one bubble or exploding into noise.
     */
    public function clusters(int $zoom): array
    {
        // At zoom 11 a cell is ~0.04 degrees; each zoom level halves it.
        $precision = max(0.0009, 0.04 / (2 ** max(0, $zoom - 11)));

        $rows = $this->builder()
            ->reorder()
            ->selectRaw('COUNT(*) AS listing_count')
            ->selectRaw('AVG(lat) AS lat')
            ->selectRaw('AVG(lng) AS lng')
            ->selectRaw('MIN(properties.id) AS sample_id')
            ->selectRaw('ROUND(lat / ?, 0) AS cell_y', [$precision])
            ->selectRaw('ROUND(lng / ?, 0) AS cell_x', [$precision])
            ->groupBy('cell_y', 'cell_x')
            ->limit(400)
            ->get();

        return $rows->map(fn ($row) => [
            'lat'   => (float) $row->lat,
            'lng'   => (float) $row->lng,
            'count' => (int) $row->listing_count,
        ])->all();
    }

    /**
     * Lightweight payload for the map pane. Never send whole listing records to
     * the browser — a busy viewport is thousands of rows (PRD §17).
     */
    public function pins(int $limit = 300): array
    {
        return $this->builder()
            ->limit($limit)
            ->get()
            ->map(function (Property $p) {
                $unit = $p->headlineUnit();

                return [
                    'id'       => $p->uuid,
                    'lat'      => $p->lat,
                    'lng'      => $p->lng,
                    'price'    => $unit?->price,
                    'period'   => $unit?->price_period?->value,
                    'realsure' => $p->isRealsureVerified(),
                ];
            })
            ->all();
    }
}
