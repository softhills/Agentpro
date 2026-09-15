<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Notifications\PasswordChanged;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Changing your own password (SEC-06).
 *
 * There was no way to do this at all. An account holder who suspected their
 * password was known to somebody else — the single most common reason anybody
 * ever changes one — had no move available except asking support to do it for
 * them, which is worse than the problem.
 */
class PasswordController extends Controller
{
    public function edit()
    {
        return view('account.password');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            /*
             * `current_password` is the rule, not a manual Hash::check. It
             * compares against the authenticated user's stored hash in constant
             * time and cannot be fooled by a stale model instance.
             *
             * It is required even though the person is already signed in: this
             * is the control that stops a borrowed, unlocked laptop from
             * becoming a permanent takeover, and it is the reason changing a
             * password is safe to expose at all.
             */
            'current_password' => ['required', 'current_password'],
            // Same strength floor as registration, including the breach check.
            // A password rule that only applies on the way in is a rule people
            // route around the first time they change one.
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()->uncompromised()],
        ], [
            'current_password.current_password' => 'That is not your current password.',
        ]);

        $user = $request->user();

        if (Hash::check($data['password'], $user->password)) {
            return back()->withErrors([
                'password' => 'That is the password you are already using. Pick a different one.',
            ]);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        /*
         * Every other session is now dead.
         *
         * The reason someone changes a password is usually that they think
         * somebody else has it, and a change that leaves the intruder's session
         * signed in achieves nothing. Laravel needs the plaintext to rewrite
         * the session password hash, which is why this happens here and cannot
         * be moved somewhere tidier.
         */
        Auth::logoutOtherDevices($data['password']);
        $request->session()->regenerate();

        // The password itself never reaches the audit trail — only the fact,
        // the actor and the time (NFR-04).
        Audit::record('user.password_changed', $user, [], [], $user->id);

        // Sent to the address on file, not to whoever just did it: if this was
        // not the account holder, this message is the only warning they get.
        $user->notify(new PasswordChanged());

        return back()->with('status', 'Password changed. Anywhere else you were signed in has been signed out.');
    }
}
