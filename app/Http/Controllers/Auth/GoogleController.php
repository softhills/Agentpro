<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Sign in with Google (FR-M1-02).
 *
 * Socialite rather than hand-rolled OAuth. The flow is small enough to write
 * badly in an afternoon and the failure mode of getting it wrong is somebody
 * else's account, so this is not the place to save a dependency.
 *
 * THE RULE THIS CLASS EXISTS TO ENFORCE. Signing in with Google proves control
 * of a Google account. It only proves control of an *email address* if Google
 * says that address is verified, and Google does not guarantee that: a Workspace
 * domain can hold addresses its administrator never proved. So an existing
 * Agentpro account is only ever linked when `email_verified` comes back true.
 * Without that check, anyone who could create a Google identity carrying a
 * victim's address could sign straight into the victim's account — and on this
 * platform that account may hold identity documents and a payout destination.
 */
class GoogleController extends Controller
{
    public function redirect()
    {
        abort_unless($this->configured(), 404);

        return Socialite::driver('google')
            // Nothing beyond identity is wanted. Asking for more would be asked
            // of the person on the consent screen, and a sign-in button that
            // wants your calendar is a sign-in button people cancel.
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    public function callback(Request $request)
    {
        abort_unless($this->configured(), 404);

        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            // Covers a cancelled consent screen, a stale state token and a
            // provider outage alike. None of them are the person's fault and
            // none of them should be a stack trace.
            return redirect()->route('login')->withErrors([
                'email' => 'We could not complete that Google sign-in. Try again, or use your password.',
            ]);
        }

        $providerId = (string) $google->getId();
        $email      = Str::lower((string) $google->getEmail());
        $verified   = (bool) ($google->user['email_verified'] ?? false);

        $link = SocialAccount::where('provider', 'google')
            ->where('provider_user_id', $providerId)
            ->first();

        // 1. Known link — the ordinary case, and the only one that needs no
        //    judgement about email at all.
        if ($link) {
            return $this->signIn($request, $link->user, 'google.signed_in');
        }

        if ($email === '') {
            return redirect()->route('login')->withErrors([
                'email' => 'Google did not share an email address with us, so we cannot sign you in that way.',
            ]);
        }

        $existing = User::where('email', $email)->first();

        // 2. An account already uses this address. Linking is only safe if
        //    Google vouches for the address; see the class docblock.
        if ($existing && ! $verified) {
            return redirect()->route('login')->withErrors([
                'email' => 'An account already uses that email address, and Google has not '
                    .'verified it. Sign in with your password instead.',
            ]);
        }

        if ($existing) {
            $this->link($existing, $providerId, $email);

            return $this->signIn($request, $existing, 'google.linked');
        }

        // 3. Nobody here yet. A new account, deliberately as a seeker: Google
        //    tells us a name and an address and nothing about whether this
        //    person has property to list, and the listing categories carry
        //    obligations somebody has to choose for themselves (FR-M1-04).
        if (! $verified) {
            return redirect()->route('login')->withErrors([
                'email' => 'Google has not verified that email address, so we cannot open an account with it.',
            ]);
        }

        $user = DB::transaction(function () use ($google, $email, $providerId) {
            $user = User::create([
                'uuid'     => Str::uuid(),
                'name'     => $google->getName() ?: Str::before($email, '@'),
                'email'    => $email,
                // No password, and none invented. See the migration.
                'category' => 'seeker',
            ]);

            // Google has verified the address, so ours is verified too — there
            // is nothing left for a confirmation email to prove.
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->link($user, $providerId, $email);

            Audit::record('user.registered', $user, [], [
                'category' => 'seeker', 'via' => 'google',
            ], $user->id);

            return $user;
        });

        return $this->signIn($request, $user, 'google.registered');
    }

    /** Shared by all three paths, so none of them can forget the suspension check. */
    private function signIn(Request $request, User $user, string $event)
    {
        if ($user->isSuspended()) {
            return redirect()->route('login')->withErrors([
                'email' => 'This account is suspended. Contact '.config('agentpro.company.email').'.',
            ]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();   // SEC-07

        Audit::record($event, $user, [], [], $user->id);

        return redirect()->intended(
            $user->canList() ? route('lister.dashboard') : route('home')
        );
    }

    private function link(User $user, string $providerId, string $email): void
    {
        SocialAccount::create([
            'user_id'          => $user->id,
            'provider'         => 'google',
            'provider_user_id' => $providerId,
            'provider_email'   => $email,
            'linked_at'        => now(),
        ]);
    }

    /** Whether Google sign-in is set up at all. @see config/services.php */
    public static function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }
}
