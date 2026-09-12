<?php

namespace App\Http\Controllers;

use App\Models\Amenity;
use App\Models\Area;
use App\Queries\PropertySearch;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $search = PropertySearch::fromRequest($request);

        $results = $search->builder()->paginate(24)->withQueryString();

        return view('pages.search', [
            'results'   => $results,
            'pins'      => $search->pins(),
            'areas'     => Area::orderBy('city')->orderBy('name')->get(),
            'amenities' => Amenity::where('is_filterable', true)->orderBy('sort_order')->get(),
            'filters'   => $request->query(),
        ]);
    }

    /**
     * Map pins for the current viewport. Separate from the HTML response so
     * panning the map does not re-render the result list server-side.
     */
    public function pins(Request $request)
    {
        return response()->json(
            PropertySearch::fromRequest($request)->pins()
        );
    }
}
