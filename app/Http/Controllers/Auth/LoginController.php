<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        // SEC-06. Throttled on email *and* IP together, so one attacker cannot
        // lock a real user out of their own account by burning their quota.
        $key = Str::transliterate(Str::lower($data['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        if (! Auth::attempt(
            ['email' => $data['email'], 'password' => $data['password']],
            (bool) ($data['remember'] ?? false)
        )) {
            RateLimiter::hit($key, 60);

            // One message for both wrong-password and no-such-account, so the
            // form cannot be used to enumerate who has an account.
            throw ValidationException::withMessages([
                'email' => 'Those details do not match our records.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();   // SEC-07

        if (Auth::user()->isSuspended()) {
            Auth::logout();
            $request->session()->invalidate();

            throw ValidationException::withMessages([
                'email' => 'This account is suspended. Contact support@agentpro.ng.',
            ]);
        }

        Audit::record('user.logged_in', Auth::user());

        return redirect()->intended(
            Auth::user()->canList() ? route('lister.dashboard') : route('home')
        );
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
