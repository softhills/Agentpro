<?php

namespace App\Services\Identity;

use App\Models\User;

/**
 * Identity verification (FR-M1-05).
 *
 * The vendor choice between VerifyMe.ng and SmileID is still open (PRD Q1), so
 * everything talks to this interface instead. Swapping drivers is then a binding
 * change in a service provider rather than a rewrite, and the auth build does
 * not have to wait on a commercial decision.
 */
interface IdentityVerifier
{
    /**
     * Submit a verification request.
     *
     * May return 'pending' where the vendor reviews asynchronously — the common
     * case, and the reason verification_state has a Pending value at all.
     */
    public function submit(User $user, VerificationSubmission $submission): VerificationResult;

    /** Vendor identifier recorded against the user for audit. */
    public function name(): string;
}
