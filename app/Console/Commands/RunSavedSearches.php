<?php

namespace App\Console\Commands;

use App\Actions\MatchSavedSearch;
use App\Models\SavedSearch;
use App\Notifications\SavedSearchMatches;
use Illuminate\Console\Command;

class RunSavedSearches extends Command
{
    protected $signature = 'agentpro:run-saved-searches {--frequency=instant : instant or daily}';

    protected $description = 'Run saved searches and notify their owners about new matches';

    public function handle(MatchSavedSearch $matcher): int
    {
        $frequency = $this->option('frequency');

        if (! in_array($frequency, ['instant', 'daily'], true)) {
            $this->error('Frequency must be instant or daily.');

            return self::FAILURE;
        }

        $searches = SavedSearch::query()
            ->where('frequency', $frequency)
            ->with('user')
            ->get();

        $notified = 0;
        $matched  = 0;

        foreach ($searches as $search) {
            if (! $search->user) {
                continue;
            }

            $properties = $matcher->unreported($search);

            if ($properties->isEmpty()) {
                // Still stamp the run, so an idle search does not look stalled
                // on the seeker's own list of saved searches.
                $search->update(['last_run_at' => now()]);

                continue;
            }

            // Recorded before sending: a failure between the two costs a missed
            // alert rather than a repeated one.
            $matcher->markReported($search, $properties);

            $search->user->notify(new SavedSearchMatches($search, $properties));

            $notified++;
            $matched += $properties->count();
        }

        $this->info(sprintf(
            'Ran %d %s searches; %d had new matches (%d listings).',
            $searches->count(),
            $frequency,
            $notified,
            $matched
        ));

        return self::SUCCESS;
    }
}
