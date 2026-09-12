<?php

namespace App\Actions;

use App\Models\SavedSearch;
use App\Models\SavedSearchMatch;
use App\Queries\PropertySearch;
use Illuminate\Support\Collection;

/**
 * Finding what a saved search should report (FR-M5-07).
 *
 * The criteria are run back through PropertySearch rather than reimplemented
 * here. That is the whole point of the rule in the README: if the matcher had
 * its own idea of what a filter means, a seeker could be alerted about a listing
 * that does not appear when they click through — or, worse, one they are not
 * allowed to see, because visibility is decided in that same query.
 *
 * The approach is pull, not push: each saved search asks "what matches me now",
 * rather than each publish asking "who wanted this". Push would mean running
 * every saved search's criteria against one listing, which is the same matching
 * problem inverted and no cheaper, and it would miss the case this feature most
 * needs to catch — a listing already published that changes into range.
 */
class MatchSavedSearch
{
    /**
     * Listings this search matches and has not reported yet.
     *
     * @return Collection<int,\App\Models\Property>
     */
    public function unreported(SavedSearch $search, int $limit = 25): Collection
    {
        $criteria = $search->criteria ?? [];

        // Bounds are stored separately from the filters but are part of the
        // query; a map-drawn search means nothing without them.
        if ($search->bounds) {
            $criteria = array_merge($criteria, $search->bounds);
        }

        $alreadyReported = $search->matches()->pluck('property_id')->all();

        $query = (new PropertySearch($criteria))->builder();

        if ($alreadyReported !== []) {
            $query->whereNotIn('properties.id', $alreadyReported);
        }

        return $query
            // Never alert someone about their own listing.
            ->where('properties.lister_id', '!=', $search->user_id)
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Record that these listings have been reported.
     *
     * Written before the notification is sent, deliberately. Sending first and
     * recording after means a failure between the two re-alerts on the next
     * run; recording first means at worst a missed alert, which is the better
     * failure of the two.
     */
    public function markReported(SavedSearch $search, Collection $properties): void
    {
        $now = now();

        foreach ($properties as $property) {
            SavedSearchMatch::updateOrCreate(
                ['saved_search_id' => $search->id, 'property_id' => $property->id],
                ['notified_at' => $now]
            );
        }

        $search->update(['last_run_at' => $now]);
    }

    /**
     * Seed the baseline when a search is first saved.
     *
     * Without this the first run reports the entire existing inventory that
     * matches — hundreds of listings the seeker has just finished scrolling
     * past. A saved search is a request about the future.
     */
    public function seedBaseline(SavedSearch $search): int
    {
        $existing = $this->unreported($search, limit: 500);

        foreach ($existing as $property) {
            SavedSearchMatch::updateOrCreate(
                ['saved_search_id' => $search->id, 'property_id' => $property->id],
                // Null: recorded as seen, but never actually notified about.
                ['notified_at' => null]
            );
        }

        $search->update(['last_run_at' => now()]);

        return $existing->count();
    }
}
