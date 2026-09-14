<?php

namespace App\Actions;

use App\Enums\LifecycleState;
use App\Models\Property;
use App\Models\User;
use App\Support\Audit;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Taking a listing off the market (FR-M2-07, FR-M2-09).
 *
 * Separate from ModerateListing, which owns the transitions *into* public
 * visibility and states plainly that it is the only path there. This owns the
 * transitions out of it, and the two have opposite trust models: moderation is
 * a decision the platform makes about a listing, unlisting is a fact its owner
 * reports about a property. Same column, different authority, so different
 * action — and the audit event names keep them apart afterwards.
 *
 * The outcome is asked for rather than inferred. "Unpublish" alone throws away
 * the one thing worth knowing about a listing that has left the market: whether
 * it left because the property found a tenant or a buyer, or because something
 * went wrong. The first two are the archive; the third is a quiet ending.
 */
class UnlistListing
{
    /**
     * @param  string  $outcome  one of Vocab::CLOSE_OUTCOMES
     * @param  string|null  $reasonCode  required when the outcome is 'other'
     */
    public function handle(
        Property $property,
        User $actor,
        string $outcome,
        ?string $reasonCode = null,
        ?string $note = null,
    ): Property {
        if (! array_key_exists($outcome, Vocab::CLOSE_OUTCOMES)) {
            throw new RuntimeException('Unknown unlisting outcome: '.$outcome);
        }

        if (! $property->lifecycle_state->canBeUnlisted()) {
            // Reachable: a moderator's policy `before()` clears every ability,
            // so the guard against unlisting a draft has to live here as well
            // as in the controller.
            throw new RuntimeException(
                'A listing in the '.$property->lifecycle_state->label().' state is not on the market.'
            );
        }

        $state = LifecycleState::from(Vocab::CLOSE_OUTCOME_STATES[$outcome]);

        return DB::transaction(function () use ($property, $actor, $outcome, $state, $reasonCode, $note) {
            $before = ['lifecycle_state' => $property->lifecycle_state->value];

            $property->update([
                'lifecycle_state' => $state->value,
                'closed_at'       => now(),
                /*
                 * The reason columns carry an unpublish and nothing else. On a
                 * sale or a letting there is no finding to record — the state
                 * itself is the whole story — and leaving a stale rejection
                 * note attached would put a moderator's words from six weeks
                 * ago underneath "Sold".
                 */
                'rejection_reason_code' => $outcome === 'other' ? $reasonCode : null,
                'rejection_note'        => $outcome === 'other' ? $note : null,
            ]);

            Audit::record('listing.'.$state->value, $property, $before, array_filter([
                'lifecycle_state' => $state->value,
                'outcome'         => $outcome,
                'reason_code'     => $reasonCode,
                // Kept on the audit record for sold and rented too, where it is
                // deliberately not kept on the listing: what the lister told us
                // is worth having, it just is not worth showing a seeker.
                'note'            => $note,
            ], fn ($v) => $v !== null), $actor->id);

            return $property->fresh();
        });
    }

    /**
     * Back on the market, for a closing the lister declared themselves.
     *
     * The display period is not extended and not restarted. A listing that ran
     * out of paid display while it was closed comes back as Expired, not
     * Published — relisting is an undo, not a renewal, and quietly handing back
     * time somebody did not pay for would make "sold" the cheapest way to pause
     * the clock (FR-M2-08).
     */
    public function relist(Property $property, User $actor): Property
    {
        if (! $property->lifecycle_state->canBeRelisted()) {
            throw new RuntimeException(
                'Only a listing closed as sold or rented can be put back on the market.'
            );
        }

        $expired = $property->expires_at !== null && $property->expires_at->isPast();
        $state = $expired ? LifecycleState::Expired : LifecycleState::Published;

        return DB::transaction(function () use ($property, $actor, $state) {
            $before = ['lifecycle_state' => $property->lifecycle_state->value];

            $property->update([
                'lifecycle_state' => $state->value,
                'closed_at'       => null,
            ]);

            Audit::record('listing.relisted', $property, $before, [
                'lifecycle_state' => $state->value,
            ], $actor->id);

            return $property->fresh();
        });
    }
}
