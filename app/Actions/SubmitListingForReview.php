<?php

namespace App\Actions;

use App\Enums\LifecycleState;
use App\Models\Property;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Draft to Submitted (FR-M2-05, FR-M2-06).
 *
 * The completeness rules live here rather than in a form request, because a
 * listing can reach submission from the edit form, from a bulk action, and later
 * from a feed import (FR-M2-15) — all three must be held to the same bar. A rule
 * that exists in only one controller is not a rule.
 */
class SubmitListingForReview
{
    /**
     * @throws ValidationException when the listing is not complete enough to review
     */
    public function __invoke(Property $property): Property
    {
        $problems = $this->problems($property);

        if ($problems !== []) {
            throw ValidationException::withMessages(['listing' => $problems]);
        }

        return DB::transaction(function () use ($property) {
            $before = ['lifecycle_state' => $property->lifecycle_state->value];

            $property->update([
                'lifecycle_state'    => LifecycleState::Submitted->value,
                'submitted_at'       => now(),
                'content_updated_at' => now(),
            ]);

            Audit::record('listing.submitted', $property, $before, [
                'lifecycle_state' => LifecycleState::Submitted->value,
            ]);

            return $property->fresh();
        });
    }

    /**
     * Everything standing between this listing and the review queue, as a list,
     * so the dashboard can tell the lister exactly what is missing instead of an
     * unhelpful "incomplete".
     *
     * @return list<string>
     */
    public function problems(Property $property): array
    {
        $problems = [];

        if (! $property->lister->isVerified()) {
            $problems[] = 'Your identity must be verified before a listing can be submitted.';
        }

        if (blank($property->description)) {
            $problems[] = 'Add a description of the property.';
        }

        if ($property->units->isEmpty()) {
            $problems[] = 'Add at least one unit with a price.';
        }

        // FR-M7-01. The whole fee-transparency proposition rests on this rule, so
        // it is enforced on the way into review rather than at publish time, when
        // a moderator would have to catch it by eye.
        foreach ($property->units as $unit) {
            if (! $unit->hasCompleteFeeBreakdown()) {
                $label = $unit->label ? 'Unit '.$unit->label : 'This listing';
                $problems[] = $label.' has no cost breakdown. Add the agent, legal and any other required fees.';
            }
        }

        if ($property->titleClaims->isEmpty()) {
            $problems[] = 'Declare at least one title document.';
        }

        $minPhotos = (int) config('agentpro.media.min_photos');

        if ($property->media->where('kind', 'photo')->count() < $minPhotos) {
            $problems[] = 'Add at least '.$minPhotos.' photographs.';
        }

        return $problems;
    }
}
