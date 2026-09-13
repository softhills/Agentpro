<?php

namespace App\Http\Controllers\Lister;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Queries\ListingAnalytics;
use Illuminate\Http\Request;

/**
 * How one listing is doing (FR-M13-01, deliverable D3).
 */
class ListingAnalyticsController extends Controller
{
    public function __invoke(Request $request, Property $property)
    {
        // The same gate as editing. Performance figures are commercially
        // sensitive — a rival agent learning that a listing has had four views
        // in a month knows exactly how to pitch against it.
        $this->authorize('update', $property);

        $analytics = new ListingAnalytics((int) config('agentpro.analytics.window_days'));

        return view('lister.analytics', [
            'property' => $property,
            'stats'    => $analytics->for($property),
        ]);
    }
}
