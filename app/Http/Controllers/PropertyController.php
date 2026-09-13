<?php

namespace App\Http\Controllers;

use App\Enums\LifecycleState;
use App\Models\Interaction;
use App\Models\Property;
use App\Support\Analytics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PropertyController extends Controller
{
    public function show(Request $request, Property $property)
    {
        // A draft or rejected listing is not merely hidden from search — it must
        // 404 on direct URL access too, or the uuid becomes a bypass (SEC-03).
        if (! in_array($property->lifecycle_state->value, LifecycleState::publiclyVisible(), true)) {
            throw new NotFoundHttpException();
        }

        /*
         * FR-M13-01. Recorded after the 404 check above, so a probe for a draft
         * uuid cannot inflate anybody's numbers, and before the page is built,
         * so a slow render does not lose the view.
         */
        Analytics::listingViewed($property);

        $property->load([
            'units.feeLines',
            'units.priceHistory',
            'media',
            'titleClaims',
            'realsureRecords.officer:id,name',
            'amenities',
            'area',
            'lister:id,name,verification_state,category',
        ]);

        return view('pages.show', [
            'property' => $property,
            'unit'     => $property->headlineUnit(),
            /*
             * What this seeker has already done to this listing, so Save reads
             * "Saved" rather than offering to save something twice. Keyed by
             * kind; a guest gets an empty set and is sent to sign in.
             */
            'mine'     => $request->user()
                ? Interaction::where('user_id', $request->user()->id)
                    ->where('property_id', $property->id)
                    ->pluck('kind')
                    ->all()
                : [],
            /*
             * HotPads shows "viewed 15 times this past week, contacted 1 time"
             * under a heading of "Competition for this rental", and it is the
             * most useful thing on their page that costs nothing to produce:
             * a seeker deciding whether to ring today wants to know whether
             * anybody else is. M13 already records both events, so this is
             * reading data we hold rather than collecting anything new.
             */
            'demand'   => $this->demandFor($property),
            /*
             * FR-M7-07, the HotPads parity item the PRD calls "nearly free" —
             * PriceHistory already exists to drive the price-drop tag, and was
             * being eager-loaded here without ever being rendered.
             */
            'priceHistory' => $property->headlineUnit()?->priceHistory
                ->sortByDesc('effective_at')->take(6)->values() ?? collect(),
        ]);
    }

    /**
     * How much interest this listing has had in the last week.
     *
     * Shown to the seeker, which is the part worth getting right: it is the
     * same figure the lister sees on their performance screen, so the two
     * cannot tell different stories about the same listing.
     *
     * Suppressed below a floor. "Viewed 2 times this week" reads as a dead
     * listing whether or not it is one, and on a platform whose whole problem
     * is supply density at launch, publishing a discouraging number about
     * somebody's property helps nobody — least of all the seeker, who learns
     * nothing from it either way.
     *
     * @return array{views: int, contacts: int}|null
     */
    private function demandFor(Property $property): ?array
    {
        /*
         * Wrapped for the same reason Analytics::record() is: this is the least
         * important thing on the page, and a listing that 500s because a
         * counter could not be read would be the measurement destroying the
         * thing it measures. Recording was already protected; reading was not,
         * until the failsafe test caught it.
         */
        try {
            $counts = DB::table('analytics_events')
                ->where('property_id', $property->id)
                ->where('occurred_at', '>=', now()->subDays(7))
                ->whereIn('name', ['detail', 'contact'])
                ->selectRaw("SUM(name = 'detail') AS views, SUM(name = 'contact') AS contacts")
                ->first();
        } catch (\Throwable $e) {
            return null;
        }

        $views = (int) ($counts->views ?? 0);

        if ($views < (int) config('agentpro.listings.demand_floor')) {
            return null;
        }

        return ['views' => $views, 'contacts' => (int) ($counts->contacts ?? 0)];
    }
}
