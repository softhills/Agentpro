<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    public function show()
    {
        return view('auth.register');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            // 'rfc' only, deliberately not 'dns'. A DNS check puts a live network
            // lookup on the signup path and rejects valid addresses on domains
            // that do not resolve from our host — email confirmation is the real
            // proof the address works.
            'email'    => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            // Nigerian mobile numbers, in either local or international form.
            'phone'    => ['required', 'string', 'regex:/^(\+?234|0)[789][01]\d{8}$/'],
            // uncompromised() checks the password against the HaveIBeenPwned
            // range API — the single most effective control against credential
            // stuffing (SEC-06). It fails open if the API is unreachable, so it
            // adds no availability risk to registration.
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()->uncompromised()],
            // FR-M1-04. 'seeker' is the default for someone who only wants to
            // save and enquire; the other five are lister categories.
            'category' => ['required', 'in:seeker,independent_agent,property_owner,sellers_agent,developer,brokerage_firm'],
        ], [
            'phone.regex' => 'Enter a Nigerian mobile number, for example 0803 000 0001.',
        ]);

        $user = User::create([
            'uuid'     => Str::uuid(),
            'name'     => $data['name'],
            'email'    => $data['email'],
            'phone'    => $this->normalisePhone($data['phone']),
            'password' => $data['password'],   // hashed by the model cast
            'category' => $data['category'],
        ]);

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();   // SEC-07

        Audit::record('user.registered', $user, [], ['category' => $user->category], $user->id);

        // A seeker has everything they need. A lister does not — send them
        // straight at verification, since nothing they came to do works until
        // it is done (FR-M1-05).
        return $user->canList()
            ? redirect()->route('verify.show')->with('status', 'Account created. Verify your identity to start listing.')
            : redirect()->route('home')->with('status', 'Welcome to Agentpro.');
    }

    /** Store one canonical form so the same number cannot register twice. */
    private function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '0')) {
            $digits = '234'.substr($digits, 1);
        }

        return '+'.$digits;
    }
}
