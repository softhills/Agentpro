<?php

namespace App\Actions;

use App\Enums\LifecycleState;
use App\Models\Property;
use App\Models\User;
use App\Notifications\ListingApproved;
use App\Notifications\ListingReturned;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Moderation decisions (M12).
 *
 * All three transitions live together because they share one invariant: a
 * listing only ever becomes publicly visible here, and every change of
 * visibility leaves an audit record naming the moderator and the reason. Split
 * across controllers, that invariant is one refactor away from being broken
 * quietly.
 */
class ModerateListing
{
    /**
     * FR-M2-06 — the only path to public visibility.
     *
     * Display duration is stamped on approval rather than on submission, so a
     * listing that sat in the queue for two days does not lose two days of the
     * display period the lister paid for (FR-M2-08).
     */
    public function approve(Property $property, User $moderator): Property
    {
        $updated = DB::transaction(function () use ($property, $moderator) {
            $before = ['lifecycle_state' => $property->lifecycle_state->value];

            $property->update([
                'lifecycle_state'      => LifecycleState::Published->value,
                'published_at'         => now(),
                'expires_at'           => now()->addDays((int) config('agentpro.display_duration_days')),
                'content_updated_at'   => now(),
                'rejection_reason_code' => null,
                'rejection_note'       => null,
            ]);

            Audit::record('listing.approved', $property, $before, [
                'lifecycle_state' => LifecycleState::Published->value,
                'expires_at'      => $property->expires_at?->toIso8601String(),
            ], $moderator->id);

            return $property->fresh();
        });

        // Notified after the transaction commits, deliberately. Sent from
        // inside, the message can reach the lister before the row it describes
        // is visible to anyone else — or describe a decision that then rolls back.
        $updated->lister->notify(new ListingApproved($updated));

        return $updated;
    }

    /**
     * FR-M2-06 — a rejection returns a reason code and a note, and the listing
     * goes back to the lister rather than into a void. The note is what the
     * lister actually reads, so it is required, not optional.
     */
    public function reject(Property $property, User $moderator, string $reasonCode, string $note): Property
    {
        $updated = DB::transaction(function () use ($property, $moderator, $reasonCode, $note) {
            $before = ['lifecycle_state' => $property->lifecycle_state->value];

            $property->update([
                'lifecycle_state'       => LifecycleState::Rejected->value,
                'rejection_reason_code' => $reasonCode,
                'rejection_note'        => $note,
            ]);

            Audit::record('listing.rejected', $property, $before, [
                'lifecycle_state' => LifecycleState::Rejected->value,
                'reason_code'     => $reasonCode,
                'note'            => $note,
            ], $moderator->id);

            return $property->fresh();
        });

        $updated->lister->notify(new ListingReturned($updated, $note));

        return $updated;
    }

    /*
     * FR-M2-07 — taking a live listing down — used to live here as
     * unpublish(). It moved to UnlistListing when the platform started asking
     * *why* a listing is coming off the market, because the answer can be
     * "sold", and a class whose own docblock says it is the only path into
     * public visibility is the wrong home for the three paths out of it.
     *
     * Unpublished, not deleted, either way: the listing, its media and its
     * audit trail stay intact, because an unpublish is frequently the first
     * step of a dispute.
     */

    /** Moderator picks the listing up, so two people do not review the same one. */
    public function claim(Property $property, User $moderator): Property
    {
        if ($property->lifecycle_state === LifecycleState::Submitted) {
            $property->update(['lifecycle_state' => LifecycleState::UnderReview->value]);

            Audit::record('listing.claimed', $property, [], [
                'lifecycle_state' => LifecycleState::UnderReview->value,
            ], $moderator->id);
        }

        return $property->fresh();
    }
}
