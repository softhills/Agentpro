<?php

namespace App\Queries;

use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * What a lister sees about one of their listings (FR-M13-01).
 *
 * The four numbers the PRD asks for — views, saves, contact initiations by
 * mode, 3D tour opens — come from two different places, and the split is not
 * arbitrary. Views and tour opens exist only as analytics events, because
 * nothing else on the platform records them. Saves come from `interactions`,
 * which already holds them exactly. Contacts come from the events because
 * `interactions` is unique per person and therefore cannot count initiations.
 *
 * Every window query reads the rollup for completed days and the raw table for
 * today, which is the only way to be both fast and current: the rollup does not
 * know about the last few hours, and the raw table will not survive the
 * retention window. @see App\Console\Commands\RollUpAnalytics
 */
class ListingAnalytics
{
    public function __construct(private int $days = 30) {}

    /** @return array<string, mixed> */
    public function for(Property $property): array
    {
        $since = now()->subDays($this->days)->startOfDay();

        $views = $this->count($property, 'detail', $since);

        return [
            'days'      => $this->days,
            'views'     => $views,
            /*
             * The lifetime figure — "since it went up", not "in the last
             * month", which is the denominator listers actually care about.
             *
             * Floored at the windowed count. The two are kept in step by
             * Analytics::listingViewed(), which writes the event and increments
             * the counter together, so in normal running the window is a subset
             * of the lifetime and this does nothing. It matters for the case
             * where they have come apart — a failed increment that was logged
             * and swallowed, a counter reset by hand — because "56 views this
             * month, 4 since it went up" is not a small discrepancy to a
             * reader. It is proof the screen is broken, and they stop believing
             * the other three numbers too.
             */
            'views_all' => max($views, (int) $property->view_count),
            'saves'     => $this->saves($property, $since),
            'contacts'  => $this->contacts($property, $since),
            'tours'     => $this->count($property, 'tour_open', $since),
            'dwell'     => $this->dwell($property, $since),
            'daily'     => $this->dailySeries($property),
        ];
    }

    /**
     * Events of one kind in the window.
     *
     * Rollup for whole days plus raw for today. The boundary is midnight, and
     * the rollup recomputes the last few days, so a row could briefly exist in
     * both — hence the strict `< today` on one side and `>= today` on the other
     * rather than an overlapping range that would double-count the busiest day.
     */
    private function count(Property $property, string $name, \DateTimeInterface $since): int
    {
        $rolled = (int) DB::table('analytics_daily')
            ->where('property_id', $property->id)
            ->where('name', $name)
            ->where('day', '>=', $since)
            ->where('day', '<', now()->startOfDay()->toDateString())
            ->sum('events');

        $today = (int) DB::table('analytics_events')
            ->where('property_id', $property->id)
            ->where('name', $name)
            ->where('occurred_at', '>=', now()->startOfDay())
            ->count();

        return $rolled + $today;
    }

    private function saves(Property $property, \DateTimeInterface $since): array
    {
        return [
            'total'  => (int) DB::table('interactions')
                ->where('property_id', $property->id)->where('kind', 'save')->count(),
            'recent' => (int) DB::table('interactions')
                ->where('property_id', $property->id)->where('kind', 'save')
                ->where('created_at', '>=', $since)->count(),
        ];
    }

    /**
     * Contact initiations broken down by how they got in touch.
     *
     * The breakdown is the useful part rather than the total: a lister whose
     * enquiries all arrive by WhatsApp runs their day differently from one
     * whose phone rings, and neither can tell which they are from a single
     * number.
     *
     * @return array{total: int, by_mode: array<string, int>}
     */
    private function contacts(Property $property, \DateTimeInterface $since): array
    {
        $byMode = DB::table('analytics_events')
            ->where('property_id', $property->id)
            ->where('name', 'contact')
            ->where('occurred_at', '>=', $since)
            ->groupBy('context')
            ->pluck(DB::raw('COUNT(*)'), 'context');

        /*
         * Read from the raw table alone, unlike everything else here.
         *
         * The rollup groups by name and property but not by context, so the
         * mode breakdown does not survive it — and rather than widen every
         * rolled-up row with a dimension only one event uses, contacts are read
         * raw. That is sound because the retention window is much longer than
         * the window this screen shows; if the two ever cross, this is the line
         * that has to change.
         */
        $modes = ['phone' => 0, 'whatsapp' => 0, 'email' => 0];

        foreach ($byMode as $mode => $count) {
            if ($mode !== null && array_key_exists($mode, $modes)) {
                $modes[$mode] = (int) $count;
            }
        }

        return ['total' => array_sum($modes), 'by_mode' => $modes];
    }

    /**
     * How long people stay with the 3D tour — objective O2's real measure.
     *
     * The median rather than the mean, because dwell is the textbook case for
     * it: one tab left open for fifty minutes drags a mean of thirty readings
     * past the target on its own, and the resulting number would say the tour
     * is working when nobody watched it. Read raw for the same reason as the
     * contact breakdown — a median cannot be recovered from daily sums.
     *
     * @return array{median: ?int, readings: int}
     */
    private function dwell(Property $property, \DateTimeInterface $since): array
    {
        $seconds = DB::table('analytics_events')
            ->where('property_id', $property->id)
            ->where('name', 'tour_dwell')
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('value')
            ->orderBy('value')
            ->pluck('value');

        if ($seconds->isEmpty()) {
            return ['median' => null, 'readings' => 0];
        }

        return ['median' => self::medianOf($seconds->all()), 'readings' => $seconds->count()];
    }

    /** @param list<int> $sorted */
    public static function medianOf(array $sorted): int
    {
        $count = count($sorted);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (int) $sorted[$middle]
            : (int) round(($sorted[$middle - 1] + $sorted[$middle]) / 2);
    }

    /**
     * Views per day for the chart.
     *
     * Zero-filled across the whole window. A sparse series draws a line
     * straight from Monday to Friday and reads as steady traffic, when what
     * actually happened was nothing at all for three days — which is the
     * signal a lister most needs to see.
     *
     * @return list<array{day: string, views: int}>
     */
    private function dailySeries(Property $property): array
    {
        $rolled = DB::table('analytics_daily')
            ->where('property_id', $property->id)
            ->where('name', 'detail')
            ->where('day', '>=', now()->subDays($this->days)->startOfDay()->toDateString())
            ->pluck('events', 'day');

        $today = (int) DB::table('analytics_events')
            ->where('property_id', $property->id)
            ->where('name', 'detail')
            ->where('occurred_at', '>=', now()->startOfDay())
            ->count();

        $series = [];

        for ($i = $this->days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->startOfDay();
            $key  = $date->toDateString();

            $series[] = [
                'day'   => $key,
                'views' => $i === 0 ? $today : (int) ($rolled[$key] ?? 0),
            ];
        }

        return $series;
    }
}
