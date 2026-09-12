<?php

namespace App\Services\Identity;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Development driver. Never bind this in production.
 *
 * It returns 'pending' by default rather than 'verified', so the whole
 * pending-state path — the blocked submission, the dashboard prompt, the review
 * queue — is exercised during development instead of being discovered on the day
 * the real vendor is connected.
 */
class StubVerifier implements IdentityVerifier
{
    public function submit(User $user, VerificationSubmission $submission): VerificationResult
    {
        $reference = 'stub_'.Str::lower(Str::random(16));

        // Deterministic outcomes so every branch is reachable in dev: a document
        // number ending 0 is rejected, one ending 9 passes instantly, anything
        // else queues as pending.
        return match (substr($submission->documentNumber, -1)) {
            '0'     => VerificationResult::rejected($reference, 'Document could not be matched to the name on the account.'),
            '9'     => VerificationResult::verified($reference),
            default => VerificationResult::pending($reference),
        };
    }

    public function name(): string
    {
        return 'stub';
    }
}
