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
     * Abilities a moderator is NOT granted by `before()`.
     *
     * Exactly one: rewriting a listing through the lister's own form. Editing
     * a listing changes what somebody's advert says, under their name, with
     * nothing on the page to show the platform did it — and the audit entry
     * recording that it happened is not the same as the lister having agreed
     * to it. Taking a listing down is the moderation answer, and it happens on
     * the review screen with a reason attached.
     *
     * `update` is deliberately NOT in here. It is what the photographs, the
     * video, the capture booking and the listing's analytics sit behind, and
     * staff keep all of those: a moderator asked to take down an unlawful
     * photograph should not have to delete the whole listing to do it.
     *
     * `delete` is granted too. It is confined to drafts by the method below
     * and by the controller, it carries a reason, and the lister is notified
     * when staff use it — answerable in a way a silent rewrite is not.
     */
    private const AUTHORSHIP = ['rewrite'];

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

    /**
     * The listing form, and nothing else.
     *
     * A separate ability rather than a check inside `update`, because the two
     * really are different questions. `update` asks whether this person may
     * change anything about the listing — photographs, a capture booking, its
     * analytics — and staff may. `rewrite` asks whether they may change what
     * the advert says, and only the person whose name is on it may.
     *
     * It defers to `update` for everything else it would otherwise repeat:
     * sold and rented stay closed, suspension still bites.
     */
    public function rewrite(User $user, Property $property): bool
    {
        return $this->owns($user, $property) && $this->update($user, $property);
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
