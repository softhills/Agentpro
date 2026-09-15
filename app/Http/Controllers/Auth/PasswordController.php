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
        $user = $request->user();

        $rules = [
            // Same strength floor as registration, including the breach check.
            // A password rule that only applies on the way in is a rule people
            // route around the first time they change one.
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()->uncompromised()],
        ];

        /*
         * `current_password` is the rule, not a manual Hash::check. It compares
         * against the authenticated user's stored hash in constant time and
         * cannot be fooled by a stale model instance.
         *
         * It is required even though the person is already signed in: this is
         * the control that stops a borrowed, unlocked laptop from becoming a
         * permanent takeover, and it is the reason changing a password is safe
         * to expose at all.
         *
         * It is skipped only when there is no password to confirm — an account
         * created through Google (FR-M1-02). Requiring it there would ask for
         * something that does not exist and lock that person out of ever
         * setting one. Their Google session is what authenticated them, which
         * is the same standard the rule enforces for everybody else.
         */
        if ($user->hasPassword()) {
            $rules['current_password'] = ['required', 'current_password'];
        }

        // Read before the write, since the write is what makes it false.
        $wasSet = $user->hasPassword();

        $data = $request->validate($rules, [
            'current_password.current_password' => 'That is not your current password.',
        ]);

        if ($user->hasPassword() && Hash::check($data['password'], $user->password)) {
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
        Audit::record($wasSet ? 'user.password_changed' : 'user.password_set', $user, [], [], $user->id);

        // Sent to the address on file, not to whoever just did it: if this was
        // not the account holder, this message is the only warning they get.
        $user->notify(new PasswordChanged());

        return back()->with('status', $wasSet
            ? 'Password changed. Anywhere else you were signed in has been signed out.'
            : 'Password set. You can now sign in with your email address as well as with Google.');
    }
}
