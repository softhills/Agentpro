<?php

namespace App\Http\Controllers;

use App\Models\Amenity;
use App\Models\Area;
use App\Queries\PropertySearch;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /** Zoom at which individual price pins replace cluster bubbles. */
    private const PIN_ZOOM = 14;

    public function index(Request $request)
    {
        $search = PropertySearch::fromRequest($request);

        $results = $search->builder()->paginate(24)->withQueryString();

        // Panning the map should not re-render the whole page, so the result
        // list is a partial the map can fetch on its own.
        if ($request->boolean('fragment')) {
            return response()->view('partials.result-list', [
                'results' => $results,
                'fragment' => true,
            ]);
        }

        return view('pages.search', [
            'results'   => $results,
            'areas'     => Area::orderBy('city')->orderBy('name')->get(),
            'amenities' => Amenity::where('is_filterable', true)->orderBy('sort_order')->get(),
            'filters'   => $request->query(),
            'mapConfig' => $this->mapConfig($request),
        ]);
    }

    /**
     * Markers for the current viewport (FR-M5-02).
     *
     * Clusters when zoomed out, individual pins when close in. The decision is
     * made server-side because it determines how much data crosses the wire,
     * and that is not something to leave to the client (NFR-02).
     */
    public function pins(Request $request)
    {
        $search = PropertySearch::fromRequest($request);
        $zoom   = (int) $request->integer('zoom', 12);

        if ($zoom < self::PIN_ZOOM) {
            return response()->json([
                'mode'    => 'clusters',
                'markers' => $search->clusters($zoom),
            ]);
        }

        return response()->json([
            'mode'    => 'pins',
            'markers' => $search->pins(),
        ]);
    }

    /**
     * Where the map opens.
     *
     * Risk R9: an empty viewport is a worse first impression than no map at all,
     * so with no area filter the map opens on the seeded coverage areas rather
     * than on a national view of mostly nothing.
     */
    private function mapConfig(Request $request): array
    {
        $area = $request->filled('area')
            ? Area::where('slug', $request->query('area'))->first()
            : null;

        return [
            'tileUrl'     => config('agentpro.map.tile_url'),
            'attribution' => config('agentpro.map.attribution'),
            'maxZoom'     => (int) config('agentpro.map.max_zoom'),
            'pinZoom'     => self::PIN_ZOOM,
            'center'      => [
                'lng' => $area?->centroid_lng ?? (float) config('agentpro.map.default_lng'),
                'lat' => $area?->centroid_lat ?? (float) config('agentpro.map.default_lat'),
            ],
            'zoom'        => $area ? ($area->default_zoom ?: 14) : (int) config('agentpro.map.default_zoom'),
            'pinsUrl'     => route('search.pins'),
            'resultsUrl'  => route('search'),
        ];
    }
}
