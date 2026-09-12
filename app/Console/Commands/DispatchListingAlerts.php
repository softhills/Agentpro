<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Models\User;
use App\Notifications\ListingUpdated;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Closes batching windows and fans out listing alerts (FR-M9-05, FR-M9-06).
 *
 * Runs on a schedule rather than being triggered by the edit itself, which is
 * what makes batching possible at all: the point is to wait and see whether
 * more changes arrive.
 */
class DispatchListingAlerts extends Command
{
    protected $signature = 'agentpro:dispatch-listing-alerts';

    protected $description = 'Send batched update alerts for listings whose change window has closed';

    public function handle(): int
    {
        $due = DB::table('pending_listing_alerts')
            ->whereNull('dispatched_at')
            ->where('window_closes_at', '<=', now())
            ->limit(200)
            ->get();

        if ($due->isEmpty()) {
            $this->info('Nothing due.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($due as $row) {
            // Claimed with a conditional update so two overlapping runs of this
            // command cannot both send the same batch.
            $claimed = DB::table('pending_listing_alerts')
                ->where('id', $row->id)
                ->whereNull('dispatched_at')
                ->update(['dispatched_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            $property = Property::find($row->property_id);

            if (! $property) {
                continue;
            }

            $recipients = $this->audienceFor($property);

            if ($recipients->isEmpty()) {
                continue;
            }

            Notification::send(
                $recipients,
                new ListingUpdated($property, json_decode($row->changes, true) ?: [])
            );

            $sent += $recipients->count();
        }

        $this->info('Dispatched '.$due->count().' batches to '.$sent.' recipients.');

        return self::SUCCESS;
    }

    /**
     * FR-M9-04: who counts as having interacted.
     *
     * Saved, rated, reported or contacted — deliberately not "viewed". A view is
     * not an expression of interest, and alerting on it would mean anyone who
     * opened a listing once gets mail about it for months.
     *
     * The lister is excluded: they made the change.
     */
    private function audienceFor(Property $property)
    {
        return User::query()
            ->whereIn('id', function ($query) use ($property) {
                $query->select('user_id')
                    ->from('interactions')
                    ->where('property_id', $property->id)
                    ->whereIn('kind', ['save', 'rate', 'report', 'contact']);
            })
            ->where('id', '!=', $property->lister_id)
            // Someone who hid the listing has said they are not interested.
            ->whereNotIn('id', function ($query) use ($property) {
                $query->select('user_id')
                    ->from('interactions')
                    ->where('property_id', $property->id)
                    ->where('kind', 'hide');
            })
            ->whereNull('deleted_at')
            ->get();
    }
}
