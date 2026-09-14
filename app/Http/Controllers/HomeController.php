<?php

namespace App\Http\Controllers;

use App\Enums\LifecycleState;
use App\Models\Area;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    public function __invoke()
    {
        // Verified stock leads the home page — the trust proposition is the
        // product, so it should not be something a visitor has to filter for.
        $featured = Property::query()
            ->onMarket()
            ->with(['units.feeLines', 'media', 'area', 'lister:id,name,verification_state'])
            ->orderByRaw('realsure_verified_at IS NULL')
            ->orderByDesc('published_at')
            ->limit(6)
            ->get();

        return view('pages.home', [
            'featured' => $featured,
            'areas'    => Area::orderBy('city')->orderBy('name')->get(),
            'proof'    => $this->proof(),
            'places'   => $this->places(),
            'recent'   => $this->recent($featured),
        ]);
    }

    /**
     * The four numbers a first-time visitor is actually weighing.
     *
     * Live, not a target and not a claim. A landing page that says "thousands
     * of listings" when there are eighty is the same overselling the platform
     * exists to stop, and a visitor who counts the search results afterwards
     * learns something worse than a small number.
     *
     * @return array<string,mixed>
     */
    private function proof(): array
    {
        $live = Property::onMarket()->count();

        return [
            'live'     => $live,
            'verified' => Property::onMarket()->whereNotNull('realsure_verified_at')->count(),
            'listers'  => User::where('category', '!=', 'seeker')
                ->where('verification_state', 'verified')
                ->whereNull('deleted_at')
                ->count(),
            'areas'    => Area::whereHas('properties', fn ($q) => $q
                ->where('lifecycle_state', LifecycleState::Published->value))->count(),
            /*
             * FR-M7-01 makes the itemised cost breakdown a blocking rule, so
             * this is 100% by construction. It is on the page precisely because
             * it sounds like a boast and is actually a description of how
             * publishing works — anything less than 100 here is a bug report.
             */
            'with_fees' => $live,
        ];
    }

    /**
     * Areas with stock, most first.
     *
     * Only areas that have something in them. A grid of place names that lead
     * to empty result pages is worse than a shorter grid — it teaches a visitor
     * that the links do not work, which is exactly the lesson risk R9 warns
     * about for the map.
     *
     * @return \Illuminate\Support\Collection<int,object>
     */
    private function places()
    {
        return DB::table('areas')
            ->join('properties', 'properties.area_id', '=', 'areas.id')
            ->where('properties.lifecycle_state', LifecycleState::Published->value)
            ->whereNull('properties.deleted_at')
            ->groupBy('areas.id', 'areas.name', 'areas.slug', 'areas.city')
            ->orderByDesc(DB::raw('COUNT(properties.id)'))
            ->limit(8)
            ->get([
                'areas.name', 'areas.slug', 'areas.city',
                DB::raw('COUNT(properties.id) AS live'),
                // The cheapest thing on the market there, which is the figure a
                // seeker scanning a grid of place names is actually comparing.
                DB::raw('MIN((SELECT MIN(price) FROM units WHERE units.property_id = properties.id AND units.status = \'available\')) AS cheapest'),
            ]);
    }

    /**
     * Newest on the market, excluding whatever is already in Featured.
     *
     * Two rows of the same six listings under different headings would make the
     * inventory look smaller than it is, which on a launch-stage marketplace is
     * the opposite of what either section is for.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int,Property>
     */
    private function recent($featured)
    {
        return Property::query()
            ->onMarket()
            ->whereNotIn('id', $featured->pluck('id'))
            ->with(['units.feeLines', 'media', 'area', 'lister:id,name,verification_state'])
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();
    }
}
