<?php

namespace App\Queries;

use App\Enums\LifecycleState;
use Illuminate\Support\Facades\DB;

/**
 * Both funnels the PRD asks for (FR-M13-02), built two different ways.
 *
 * The seeker funnel — search, results, detail, contact — has to come from
 * recorded events, because none of those steps leaves a trace anywhere else.
 *
 * The lister funnel — register, verify, submit, publish, upgrade — is derived
 * from `users`, `properties` and `orders` instead, and no event is recorded for
 * any of it. Every step is already a timestamp on a row the platform keeps for
 * its own sake, so instrumenting it again would produce a second version of the
 * truth that drifts the first time a code path writes one and not the other.
 * It also means the lister funnel is complete for all time rather than only as
 * far back as the event retention window, and is unaffected by consent — there
 * is nothing to consent to in counting your own customers.
 *
 * One rule governs everything below: a rate is never reported without the
 * population it was measured over. A conversion figure computed only across
 * visitors who accepted cookies is not the site's conversion rate, and the only
 * safe way to stop somebody reading it as one is to put the coverage next to it
 * every time.
 */
class Funnel
{
    public function __construct(private int $days = 30) {}

    /** @return array<string, mixed> */
    public function seeker(): array
    {
        $since = now()->subDays($this->days)->startOfDay();

        $searches = $this->events('search', $since);
        $details  = $this->events('detail', $since);
        $contacts = $this->events('contact', $since);

        return [
            'days'     => $this->days,
            'steps'    => [
                ['name' => 'Searched',          'count' => $searches],
                ['name' => 'Opened a listing',  'count' => $details],
                ['name' => 'Started a contact', 'count' => $contacts],
            ],
            // O5's measure, and the number the whole objective turns on.
            'contact_rate' => $details > 0 ? round($contacts / $details * 100, 1) : null,
            'target_rate'  => 6.0,
            'empty_searches' => $this->emptySearches($since),
            'consent'  => $this->consentCoverage($since),
            'journeys' => $this->journeys($since),
        ];
    }

    /**
     * The lister funnel, from timestamps that already existed.
     *
     * Cumulative rather than windowed: "how many people who registered went on
     * to publish" is a question about the whole population, and answering it
     * over thirty days would compare this month's registrations with listings
     * published by people who joined last year.
     *
     * @return array<string, mixed>
     */
    public function lister(): array
    {
        $listers = DB::table('users')
            ->whereNull('deleted_at')
            ->where('category', '!=', 'seeker')
            ->count();

        $verified = DB::table('users')
            ->whereNull('deleted_at')
            ->where('category', '!=', 'seeker')
            ->where('verification_state', 'verified')
            ->count();

        $submitted = DB::table('properties')
            ->whereNull('deleted_at')
            ->whereNotNull('submitted_at')
            ->distinct()->count('lister_id');

        $published = DB::table('properties')
            ->whereNull('deleted_at')
            ->whereNotNull('published_at')
            ->distinct()->count('lister_id');

        $upgraded = DB::table('orders')
            ->where('item_type', 'scan_3d')
            ->where('state', 'paid')
            ->distinct()->count('user_id');

        return [
            'steps' => [
                ['name' => 'Registered as a lister', 'count' => $listers],
                ['name' => 'Passed identity checks', 'count' => $verified],
                ['name' => 'Submitted a listing',    'count' => $submitted],
                ['name' => 'Got one published',      'count' => $published],
                ['name' => 'Bought a 3D capture',    'count' => $upgraded],
            ],
            // O6: upgrade conversion is measured against listers who actually
            // have something publishable, not against everyone who signed up.
            'upgrade_rate' => $published > 0 ? round($upgraded / $published * 100, 1) : null,
            'target_upgrade' => 3.0,
        ];
    }

    /**
     * What share of traffic agreed to be followed.
     *
     * Published next to every journey figure rather than buried in a footnote.
     * If eleven per cent of visitors consented, a journey-level conversion rate
     * describes eleven per cent of the market, and whoever reads it has to be
     * told that before they act on it.
     *
     * @return array{events: int, consented: int, share: ?float}
     */
    private function consentCoverage(\DateTimeInterface $since): array
    {
        $row = DB::table('analytics_events')
            ->where('occurred_at', '>=', $since)
            ->selectRaw('COUNT(*) AS total, COUNT(visitor_id) AS consented')
            ->first();

        $total = (int) ($row->total ?? 0);
        $consented = (int) ($row->consented ?? 0);

        return [
            'events'    => $total,
            'consented' => $consented,
            'share'     => $total > 0 ? round($consented / $total * 100, 1) : null,
        ];
    }

    /**
     * Journey-level conversion, over consenting visitors only.
     *
     * This is the part that genuinely needs a visitor id: not "how many
     * contacts were there" but "of the people who opened a listing, how many of
     * those same people went on to make contact". Aggregate counts cannot
     * answer it, which is exactly why it costs consent.
     *
     * @return array{visitors: int, reached_detail: int, reached_contact: int, rate: ?float}
     */
    private function journeys(\DateTimeInterface $since): array
    {
        $visitors = (int) DB::table('analytics_events')
            ->whereNotNull('visitor_id')->where('occurred_at', '>=', $since)
            ->distinct()->count('visitor_id');

        $reachedDetail = (int) DB::table('analytics_events')
            ->whereNotNull('visitor_id')->where('occurred_at', '>=', $since)
            ->where('name', 'detail')
            ->distinct()->count('visitor_id');

        $reachedContact = (int) DB::table('analytics_events')
            ->whereNotNull('visitor_id')->where('occurred_at', '>=', $since)
            ->where('name', 'contact')
            ->distinct()->count('visitor_id');

        return [
            'visitors'        => $visitors,
            'reached_detail'  => $reachedDetail,
            'reached_contact' => $reachedContact,
            'rate' => $reachedDetail > 0 ? round($reachedContact / $reachedDetail * 100, 1) : null,
        ];
    }

    /**
     * Searches that returned nothing.
     *
     * The most actionable number on the whole screen, and the reason the result
     * count is carried on the event at all: a rising count of empty searches is
     * a map of where seekers are looking and supply is not, which is objective
     * O4's problem stated from the demand side.
     *
     * @return array{searches: int, empty: int, share: ?float}
     */
    private function emptySearches(\DateTimeInterface $since): array
    {
        $row = DB::table('analytics_events')
            ->where('name', 'results')
            ->where('occurred_at', '>=', $since)
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN value = 0 THEN 1 ELSE 0 END) AS blank')
            ->first();

        $total = (int) ($row->total ?? 0);
        $blank = (int) ($row->blank ?? 0);

        return [
            'searches' => $total,
            'empty'    => $blank,
            'share'    => $total > 0 ? round($blank / $total * 100, 1) : null,
        ];
    }

    /**
     * O2's second half: how long a tour actually holds someone.
     *
     * Counting listings that have a tour says nothing about whether remote
     * viewing replaces a physical trip. This does, and it is the measure the
     * objective names.
     *
     * @return array{median: ?int, readings: int, target: int}
     */
    public function tourDwell(): array
    {
        $seconds = DB::table('analytics_events')
            ->where('name', 'tour_dwell')
            ->where('occurred_at', '>=', now()->subDays($this->days)->startOfDay())
            ->whereNotNull('value')
            ->orderBy('value')
            ->pluck('value')
            ->all();

        return [
            'median'   => $seconds === [] ? null : ListingAnalytics::medianOf($seconds),
            'readings' => count($seconds),
            'target'   => 90,
        ];
    }

    /** Rollup for completed days, raw for today. @see ListingAnalytics */
    private function events(string $name, \DateTimeInterface $since): int
    {
        $rolled = (int) DB::table('analytics_daily')
            ->where('name', $name)
            ->whereNull('property_id')
            ->where('day', '>=', $since)
            ->where('day', '<', now()->startOfDay()->toDateString())
            ->sum('events');

        /*
         * Listing-scoped events roll up under their property, so summing only
         * the platform-wide rows above would miss every `detail` and `contact`.
         * They are counted here in full rather than split across two branches.
         */
        $rolledScoped = (int) DB::table('analytics_daily')
            ->where('name', $name)
            ->whereNotNull('property_id')
            ->where('day', '>=', $since)
            ->where('day', '<', now()->startOfDay()->toDateString())
            ->sum('events');

        $today = (int) DB::table('analytics_events')
            ->where('name', $name)
            ->where('occurred_at', '>=', now()->startOfDay())
            ->count();

        return $rolled + $rolledScoped + $today;
    }

    /**
     * Where seekers looked and found nothing, by area.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function coldSpots(int $limit = 8)
    {
        return DB::table('properties')
            ->join('areas', 'areas.id', '=', 'properties.area_id')
            ->where('properties.lifecycle_state', LifecycleState::Published->value)
            ->whereNull('properties.deleted_at')
            ->groupBy('areas.id', 'areas.name', 'areas.city')
            ->orderByDesc(DB::raw('SUM(properties.view_count)'))
            ->limit($limit)
            ->get([
                'areas.name',
                'areas.city',
                DB::raw('COUNT(*) AS listings'),
                DB::raw('SUM(properties.view_count) AS views'),
            ]);
    }
}
