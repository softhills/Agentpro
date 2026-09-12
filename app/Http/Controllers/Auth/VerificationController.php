<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Identity\IdentityVerifier;
use App\Services\Identity\VerificationSubmission;
use App\Notifications\VerificationDecided;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Lister identity verification (FR-M1-05, FR-M1-06).
 *
 * State machine: Unverified -> Pending -> Verified | Rejected, plus Suspended.
 * Every state is shown to the user with the next action spelled out, because
 * "pending" with no explanation is indistinguishable from "broken".
 */
class VerificationController extends Controller
{
    public function show()
    {
        return view('auth.verify', ['user' => Auth::user()]);
    }

    public function store(Request $request, IdentityVerifier $verifier)
    {
        $user = $request->user();

        abort_unless($user->canList(), 403, 'Only listing accounts need verification.');

        if ($user->isVerified()) {
            return redirect()->route('lister.dashboard');
        }

        $data = $request->validate([
            'document_type'   => ['required', 'in:nin,bvn,passport,drivers_licence'],
            'document_number' => ['required', 'string', 'min:6', 'max:32', 'regex:/^[A-Za-z0-9\-]+$/'],
            'cac_number'      => ['nullable', 'string', 'max:32'],
        ]);

        $submission = new VerificationSubmission(
            documentType: $data['document_type'],
            documentNumber: $data['document_number'],
            cacNumber: $data['cac_number'] ?? null,
        );

        // NFR-04: the context helper exists so the document number cannot be
        // logged by accident here.
        Log::info('identity.verification.submitted', [
            'user_id' => $user->id,
            'vendor'  => $verifier->name(),
        ] + $submission->toLogContext());

        $user->forceFill([
            'verification_state' => 'pending',
            'verification_vendor' => $verifier->name(),
        ])->save();

        $result = $verifier->submit($user, $submission);

        $user->forceFill([
            'verification_state'     => $result->state,
            'verification_reference' => $result->reference,
            'verified_at'            => $result->state === 'verified' ? now() : null,
        ])->save();

        Audit::record('user.verification_' . $result->state, $user, [], [
            'vendor'    => $verifier->name(),
            'reference' => $result->reference,
            'reason'    => $result->reason,
        ]);

        // Publishing is blocked until this decision lands, so the lister is
        // told rather than left to poll the page.
        if (in_array($result->state, ['verified', 'rejected'], true)) {
            $user->notify(new VerificationDecided($result->state, $result->reason));
        }

        return redirect()->route('verify.show')->with('status', match ($result->state) {
            'verified' => 'Your identity is verified. You can publish listings now.',
            'rejected' => $result->reason ?? 'We could not verify those details.',
            default    => 'Submitted. Checks usually complete within a few hours, and we will notify you.',
        });
    }
}
