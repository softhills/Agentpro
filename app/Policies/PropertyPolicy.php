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
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isStaff('moderator')) {
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
