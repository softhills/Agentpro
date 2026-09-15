<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * Sign in with Google (FR-M1-02).
 *
 * The rule these tests exist to hold: signing in with Google proves control of
 * a Google account. It proves control of an *email address* only when Google
 * says that address is verified — a Workspace domain can hold addresses its
 * administrator never proved. So an existing Agentpro account is linked only on
 * a verified address. Without that, anybody able to create a Google identity
 * carrying a victim's address could sign straight into the victim's account,
 * which on this platform holds identity documents and a payout destination.
 */
class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id'     => 'test-client-id',
            'services.google.client_secret' => 'test-secret',
            'services.google.redirect'      => 'https://agentpro.test/auth/google/callback',
        ]);
    }

    /** Stands in for the round trip to Google. */
    private function googleReturns(string $id, string $email, bool $verified, string $name = 'Obafemi Bankole'): void
    {
        $user = (new SocialiteUser)->setRaw(['email_verified' => $verified])
            ->map(['id' => $id, 'name' => $name, 'email' => $email]);

        $provider = Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('user')->andReturn($user);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function existing(string $email, array $attrs = []): User
    {
        return User::forceCreate(array_merge([
            'uuid' => Str::uuid(), 'name' => 'Tunde Adeyemi', 'email' => $email,
            'password' => Hash::make('correct-horse-7-battery'),
            'category' => 'seeker', 'verification_state' => 'verified',
        ], $attrs));
    }

    // --------------------------------------------------------- new accounts

    public function test_a_verified_google_address_opens_a_new_account(): void
    {
        $this->googleReturns('google-123', 'new@example.test', verified: true);

        $this->get(route('auth.google.callback'))->assertRedirect();

        $user = User::where('email', 'new@example.test')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        // Google vouched for the address, so there is nothing for a
        // confirmation email left to prove.
        $this->assertNotNull($user->email_verified_at);
        // No password, and none invented.
        $this->assertNull($user->password);
        $this->assertFalse($user->hasPassword());
        // FR-M1-04: Google says nothing about whether this person has property
        // to list, and the listing categories carry obligations.
        $this->assertSame('seeker', $user->category);
        $this->assertDatabaseHas('social_accounts', [
            'provider' => 'google', 'provider_user_id' => 'google-123', 'user_id' => $user->id,
        ]);
    }

    public function test_an_unverified_google_address_opens_nothing(): void
    {
        $this->googleReturns('google-123', 'spoofed@example.test', verified: false);

        $this->get(route('auth.google.callback'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    // -------------------------------------------------------------- linking

    /** The attack this whole feature has to survive. */
    public function test_an_unverified_address_cannot_take_over_an_existing_account(): void
    {
        $victim = $this->existing('victim@example.test');

        $this->googleReturns('attacker-google-id', 'victim@example.test', verified: false);

        $this->get(route('auth.google.callback'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertSame(0, $victim->socialAccounts()->count());
    }

    public function test_a_verified_address_links_to_the_account_that_already_uses_it(): void
    {
        $user = $this->existing('both@example.test');

        $this->googleReturns('google-456', 'both@example.test', verified: true);

        $this->get(route('auth.google.callback'))->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame(1, $user->socialAccounts()->count());
        // The password still works; linking adds a way in, it does not replace one.
        $this->assertTrue(Hash::check('correct-horse-7-battery', $user->fresh()->password));
    }

    /**
     * Returning users are matched on the provider's subject id, never on email.
     * Matching on email would follow the address rather than the person.
     */
    public function test_a_returning_user_is_matched_on_the_provider_id_not_the_email(): void
    {
        $user = $this->existing('old-address@example.test');
        SocialAccount::create([
            'user_id' => $user->id, 'provider' => 'google',
            'provider_user_id' => 'google-789', 'provider_email' => 'old-address@example.test',
            'linked_at' => now(),
        ]);

        // Same person at Google, who has since changed their address there.
        $this->googleReturns('google-789', 'new-address@example.test', verified: true);

        $this->get(route('auth.google.callback'))->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
    }

    // ------------------------------------------------------------- refusals

    public function test_a_suspended_account_is_refused_the_same_as_on_the_password_form(): void
    {
        $user = $this->existing('suspended@example.test', ['verification_state' => 'suspended']);
        SocialAccount::create([
            'user_id' => $user->id, 'provider' => 'google',
            'provider_user_id' => 'google-999', 'linked_at' => now(),
        ]);

        $this->googleReturns('google-999', 'suspended@example.test', verified: true);

        $this->get(route('auth.google.callback'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** An account with no password cannot be signed into with one. */
    public function test_a_google_only_account_cannot_be_signed_into_with_a_password(): void
    {
        $this->googleReturns('google-123', 'nopass@example.test', verified: true);
        $this->get(route('auth.google.callback'));
        $this->post(route('logout'));

        foreach (['', 'anything', 'password'] as $guess) {
            $this->post(route('login'), ['email' => 'nopass@example.test', 'password' => $guess]);
            $this->assertGuest();
        }
    }

    // -------------------------------------------------------- configuration

    /** No client id means no half-working auth surface. */
    public function test_the_routes_and_the_button_disappear_when_it_is_not_configured(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $this->get(route('auth.google'))->assertNotFound();
        $this->get(route('auth.google.callback'))->assertNotFound();
        $this->get(route('login'))->assertOk()->assertDontSee('Continue with Google');
    }

    public function test_the_button_is_offered_when_it_is_configured(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Continue with Google');
        $this->get(route('register'))->assertOk()->assertSee('Continue with Google');
    }

    // ------------------------------------------- setting a password later

    /**
     * The account screen has to offer *set* rather than *change*, and must not
     * demand a current password that does not exist — which would lock a
     * Google user out of ever having one.
     */
    public function test_a_google_user_can_set_a_password_without_a_current_one(): void
    {
        $this->googleReturns('google-123', 'nopass@example.test', verified: true);
        $this->get(route('auth.google.callback'));

        $user = User::where('email', 'nopass@example.test')->firstOrFail();

        $this->get(route('password.edit'))->assertOk()->assertSee('Set a password');

        $this->put(route('password.update'), [
            'password'              => 'lagos-9-ikoyi-terrace',
            'password_confirmation' => 'lagos-9-ikoyi-terrace',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('lagos-9-ikoyi-terrace', $user->fresh()->password));
        $this->assertDatabaseHas('audit_events', ['action' => 'user.password_set', 'actor_id' => $user->id]);
    }
}
