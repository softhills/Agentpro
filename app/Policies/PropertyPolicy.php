<?php

namespace App\Policies;

use App\Enums\LifecycleState;
use App\Models\Property;
use App\Models\User;

/**
 * Authorisation for listings (SEC-03).
 *
 * Ownership is checked on every lister action. The uuid in the URL is an
 * identifier, not a capability — knowing it must never be enough to read or
 * change someone else's draft.
 */
class PropertyPolicy
{
    /**
     * Abilities a moderator is NOT granted by `before()`, because they are
     * authorship rather than moderation.
     *
     * Moderating decides what happens to a listing — approved, rejected,
     * unlisted — and happens on the review screen with a reason recorded
     * against it. Editing somebody's listing changes what their advert says,
     * under their name, with nothing on the page to show the platform did it.
     * The audit entry records that it happened, which is not the same as the
     * lister having agreed to it.
     *
     * It reaches further than the listing form, and deliberately: everything
     * behind `update` is authorship. The photographs and video, the capture
     * booking, and the listing's analytics — which the route comment already
     * calls commercially sensitive to the lister — all sit behind this one
     * ability, and none of them is a moderation decision.
     *
     * `delete` is still granted. It is the one way to clear a draft that
     * should not exist, it is confined to drafts by the policy below and by
     * the controller, and the lister is notified when staff use it, so it is
     * answerable in a way that a silent rewrite is not.
     */
    private const AUTHORSHIP = ['update'];

    public function before(User $user, string $ability): ?bool
    {
        if ($user->isStaff('moderator') && ! in_array($ability, self::AUTHORSHIP, true)) {
            return true;
        }

        if ($user->isSuspended()) {
            return false;
        }

        return null;
    }

    public function view(User $user, Property $property): bool
    {
        return $this->owns($user, $property)
            || in_array($property->lifecycle_state->value, LifecycleState::publiclyVisible(), true);
    }

    public function create(User $user): bool
    {
        return $user->canList();
    }

    public function update(User $user, Property $property): bool
    {
        // A published listing stays editable — FR-M9-05 turns a material edit
        // into an alert rather than forbidding the edit.
        return $this->owns($user, $property)
            && ! in_array($property->lifecycle_state->value, ['sold', 'rented'], true);
    }

    /** FR-M1-05: verification gates submission, not merely publication. */
    public function submit(User $user, Property $property): bool
    {
        return $this->owns($user, $property)
            && $user->isVerified()
            && in_array($property->lifecycle_state->value, ['draft', 'rejected', 'unpublished'], true);
    }

    /**
     * FR-M2-07 / FR-M2-09: take it off the market.
     *
     * The owner and any moderator — the latter through `before()`. Both are
     * legitimate: a lister knows the flat is let, and the platform has to be
     * able to pull a listing that should not be up, without waiting for the
     * person who put it there to agree.
     */
    public function unlist(User $user, Property $property): bool
    {
        return $this->owns($user, $property)
            && $property->lifecycle_state->canBeUnlisted();
    }

    /** Undo a closing the lister declared. See LifecycleState::canBeRelisted(). */
    public function relist(User $user, Property $property): bool
    {
        return $this->owns($user, $property)
            && $property->lifecycle_state->canBeRelisted();
    }

    public function delete(User $user, Property $property): bool
    {
        return $this->owns($user, $property)
            && $property->lifecycle_state === LifecycleState::Draft;
    }

    private function owns(User $user, Property $property): bool
    {
        if ($property->lister_id === $user->id) {
            return true;
        }

        // FR-M1-08: firm and developer seats share inventory.
        return $user->organisation_id !== null
            && $property->organisation_id === $user->organisation_id;
    }
}
