<?php

namespace App\Http\Controllers;

use App\Actions\MatchSavedSearch;
use App\Models\SavedSearch;
use App\Queries\PropertySearch;
use App\Support\Audit;
use Illuminate\Http\Request;

class SavedSearchController extends Controller
{
    public function index(Request $request)
    {
        return view('account.saved-searches', [
            'searches' => SavedSearch::where('user_id', $request->user()->id)
                ->withCount(['matches as new_count' => fn ($q) => $q->whereNotNull('notified_at')])
                ->latest()
                ->get(),
        ]);
    }

    /**
     * Save the search the seeker is currently looking at.
     *
     * The criteria are validated through PropertySearch's own rules rather than
     * a second list here, so a filter that cannot be searched cannot be saved
     * either — and the two cannot drift apart.
     */
    public function store(Request $request, MatchSavedSearch $matcher)
    {
        $criteria = PropertySearch::fromRequest($request)->filters();

        $data = $request->validate([
            'name'      => ['nullable', 'string', 'max:80'],
            'frequency' => ['nullable', 'in:instant,daily,off'],
        ]);

        $bounds = array_intersect_key($criteria, array_flip(['south', 'west', 'north', 'east']));
        $filters = array_diff_key($criteria, $bounds);

        $search = SavedSearch::create([
            'user_id'   => $request->user()->id,
            // A nullable rule leaves the key out of the validated array entirely
            // when the field was not submitted, so the default has to survive a
            // missing key as well as an empty one.
            'name'      => ($data['name'] ?? null) ?: $this->nameFor($filters),
            'criteria'  => $filters,
            'bounds'    => $bounds ?: null,
            'frequency' => $data['frequency'] ?? 'daily',
        ]);

        // Everything matching right now is recorded as already seen. A saved
        // search is a request about the future, not a replay of the results the
        // seeker has just scrolled through.
        $seeded = $matcher->seedBaseline($search);

        Audit::record('saved_search.created', $search, [], [
            'criteria'  => $filters,
            'frequency' => $search->frequency,
            'baseline'  => $seeded,
        ]);

        return back()->with(
            'status',
            'Search saved. We will tell you when something new matches — '
            .($seeded > 0 ? 'the '.$seeded.' listings already matching are not counted as new.' : 'nothing matches it yet.')
        );
    }

    public function update(Request $request, SavedSearch $savedSearch)
    {
        abort_unless($savedSearch->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'frequency' => ['required', 'in:instant,daily,off'],
        ]);

        $savedSearch->update(['frequency' => $data['frequency']]);

        return back()->with('status', 'Alert frequency updated.');
    }

    public function destroy(Request $request, SavedSearch $savedSearch)
    {
        abort_unless($savedSearch->user_id === $request->user()->id, 404);

        $savedSearch->delete();

        return back()->with('status', 'Saved search removed.');
    }

    /** A default name, so saving does not demand a decision first. */
    private function nameFor(array $filters): string
    {
        if (! empty($filters['q'])) {
            return ucfirst($filters['q']);
        }

        return match ($filters['type'] ?? null) {
            'land'      => 'Land search',
            'house'     => 'Houses',
            'apartment' => 'Apartments',
            default     => 'Property search',
        };
    }
}
