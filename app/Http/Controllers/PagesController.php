<?php

namespace App\Http\Controllers;

use App\Enums\LifecycleState;
use App\Models\Area;
use App\Models\Property;
use App\Models\User;
use App\Support\PersonalData;
use App\Support\Vocab;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The corporate and product pages (deliverable D2).
 *
 * Every one of these was a dead `href="#"` in the header and footer until now,
 * which is the most visible thing that can be wrong with a site — a visitor who
 * clicks Terms and gets nothing has learned something about the company before
 * they have looked at a single listing.
 *
 * The governing decision here is that these pages are built from the database
 * rather than from marketing copy. An area page that says "Lekki Phase 1 is a
 * vibrant neighbourhood" tells a seeker nothing they could act on; one that says
 * how many listings are live, what the middle of the price range actually is and
 * whether 3D capture is available there is worth opening. It also cannot go
 * stale, which invented prose always does.
 */
class PagesController extends Controller
{
    /** What RealSure is, and what the badge does and does not claim (M6). */
    public function realsure()
    {
        $verified = Property::onMarket()->whereNotNull('realsure_verified_at')->count();

        return view('pages.realsure', [
            'components'   => Vocab::REALSURE_COMPONENTS,
            'verification' => Vocab::REALSURE_VERIFICATION,
            'production'   => Vocab::REALSURE_PRODUCTION,
            'verifiedCount' => $verified,
            'price'        => (int) config('agentpro.prices.realsure'),
            // Named on the page because the badge is only worth what its
            // weakest rule allows: a visitor should be able to read what has
            // to be true before Agentpro will put the mark on anything.
            'minimum'      => (int) config('agentpro.realsure.minimum_verification_components'),
            'titleRequired' => (bool) config('agentpro.realsure.require_title_verification'),
        ]);
    }

    /** Where Agentpro operates, from the areas that actually have stock. */
    public function areas()
    {
        $areas = Area::query()
            ->withCount(['properties as live_count' => fn ($q) => $q
                ->where('lifecycle_state', LifecycleState::Published->value)])
            ->orderBy('city')
            ->orderByDesc('is_scan_coverage')
            ->orderBy('name')
            ->get()
            ->groupBy('city');

        return view('pages.areas', ['cities' => $areas]);
    }

    /**
     * One area.
     *
     * Deliberately not a landing page with a paragraph about the
     * neighbourhood — that is Release 2's neighbourhood intelligence module
     * (M8), and writing a placeholder version of it now would mean writing
     * something nobody checked about somewhere people live.
     */
    public function area(Area $area)
    {
        $live = Property::onMarket()->where('area_id', $area->id);

        $prices = (clone $live)
            ->join('units', 'units.property_id', '=', 'properties.id')
            ->where('units.is_primary', true)
            ->orderBy('units.price')
            ->pluck('units.price')
            ->all();

        return view('pages.area', [
            'area'    => $area,
            'live'    => (clone $live)
                ->with(['units.feeLines', 'media', 'area', 'lister:id,name,verification_state'])
                ->orderByRaw('realsure_verified_at IS NULL')
                ->orderByDesc('published_at')
                ->limit(12)
                ->get(),
            'count'   => count($prices),
            // The median, not the mean: one ₦900m penthouse in a street of
            // ₦40m flats would make the average a number nobody could use.
            'median'  => $prices === [] ? null : $prices[intdiv(count($prices), 2)],
            'cheapest' => $prices[0] ?? null,
            'verified' => (clone $live)->whereNotNull('realsure_verified_at')->count(),
        ]);
    }

    /**
     * The verified lister directory (FR-M1-07).
     *
     * Only verified listers appear. An unverified account has passed no check,
     * so a page listing it would be doing exactly what the platform exists to
     * stop — lending credibility that nobody earned.
     */
    public function agents(Request $request)
    {
        $agents = User::query()
            ->where('category', '!=', 'seeker')
            ->where('verification_state', 'verified')
            ->whereNull('deleted_at')
            ->withCount(['properties as live_count' => fn ($q) => $q
                ->where('lifecycle_state', LifecycleState::Published->value)])
            ->when($request->query('q'), fn ($q, $term) => $q->where('name', 'like', '%'.$term.'%'))
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->having('live_count', '>', 0)
            ->orderByDesc('live_count')
            ->paginate(24)
            ->withQueryString();

        return view('pages.agents', [
            'agents'     => $agents,
            'term'       => $request->query('q'),
            'category'   => $request->query('category'),
            'categories' => [
                'independent_agent' => 'Independent agent',
                'property_owner'    => 'Property owner',
                'sellers_agent'     => "Seller's agent",
                'developer'         => 'Developer',
                'brokerage_firm'    => 'Brokerage firm',
            ],
        ]);
    }

    /** One lister's public profile (FR-M1-07). */
    public function agent(User $user)
    {
        // Keyed on the uuid by the route, but an unverified or closed account
        // must 404 rather than render an empty profile — the directory it is
        // reached from only contains verified people, and a direct URL should
        // not be a way around that.
        if ($user->category === 'seeker' || ! $user->isVerified() || $user->trashed()) {
            throw new NotFoundHttpException();
        }

        $listings = Property::onMarket()
            ->where('lister_id', $user->id)
            ->with(['units.feeLines', 'media', 'area', 'lister:id,name,verification_state'])
            ->orderByRaw('realsure_verified_at IS NULL')
            ->orderByDesc('published_at')
            ->paginate(12);

        /*
         * FR-M6-08: ratings aggregate to the lister profile.
         *
         * Averaged across their live listings rather than stored on the user,
         * so it cannot drift from the ratings it claims to summarise. Shown
         * only once there are enough of them to mean anything — a "5.0" from a
         * single rating is not a reputation, and publishing it as one would
         * mislead in the lister's favour.
         */
        $ratings = DB::table('interactions')
            ->join('properties', 'properties.id', '=', 'interactions.property_id')
            ->where('properties.lister_id', $user->id)
            ->where('interactions.kind', 'rate')
            ->whereNotNull('interactions.rating')
            ->selectRaw('AVG(interactions.rating) AS average, COUNT(*) AS total')
            ->first();

        $minimum = (int) config('agentpro.profiles.minimum_ratings');

        return view('pages.agent', [
            'agent'    => $user,
            'listings' => $listings,
            'rating'   => $ratings && $ratings->total >= $minimum
                ? ['average' => round((float) $ratings->average, 1), 'total' => (int) $ratings->total]
                : null,
            'ratingsSoFar' => (int) ($ratings->total ?? 0),
            'minimumRatings' => $minimum,
            'verifiedListings' => Property::onMarket()
                ->where('lister_id', $user->id)->whereNotNull('realsure_verified_at')->count(),
        ]);
    }

    public function about()
    {
        return view('pages.about', [
            'live'     => Property::onMarket()->count(),
            'listers'  => User::where('category', '!=', 'seeker')
                ->where('verification_state', 'verified')->whereNull('deleted_at')->count(),
            'areas'    => Area::scanCoverage()->count(),
        ]);
    }

    public function terms()
    {
        return view('pages.terms', [
            'titleTypes' => Vocab::TITLE_TYPES,
            'reasons'    => Vocab::REPORT_REASONS,
        ]);
    }

    /**
     * The privacy notice (NDPA 2023 s. 27).
     *
     * Generated from `PersonalData::map()` — the same manifest the erasure runs
     * on and the same one the data export explains itself with. A privacy
     * notice written by hand starts accurate and drifts the first time a table
     * is added; this one cannot, because the guard test that forces every new
     * table into the manifest is also the thing that keeps this page complete.
     *
     * The prose around it still needs a Nigerian lawyer. What it says about the
     * system is true, which is the part software can be responsible for.
     */
    public function privacy()
    {
        return view('pages.privacy', [
            'disposal'   => PersonalData::summary(),
            'contact'    => config('agentpro.privacy.contact'),
            'graceHours' => PersonalData::erasureGraceHours(),
            'retention'  => (int) config('agentpro.analytics.retention_days'),
        ]);
    }
}
