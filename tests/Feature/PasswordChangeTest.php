<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Changing your own password (SEC-06).
 *
 * There was no way to do this at all. An account holder who suspected somebody
 * else knew their password — the usual reason anybody ever changes one — had no
 * move available except asking support to do it for them.
 */
class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $password = 'correct-horse-7-battery'): User
    {
        return User::forceCreate([
            'uuid' => Str::uuid(),
            'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => Hash::make($password),
            'category' => 'seeker',
            'verification_state' => 'verified',
        ]);
    }

    public function test_a_signed_in_person_can_change_their_own_password(): void
    {
        Notification::fake();

        $user = $this->user();

        $this->actingAs($user)->put(route('password.update'), [
            'current_password'      => 'correct-horse-7-battery',
            'password'              => 'lagos-9-ikoyi-terrace',
            'password_confirmation' => 'lagos-9-ikoyi-terrace',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('lagos-9-ikoyi-terrace', $user->fresh()->password));
    }

    /**
     * The control that makes exposing this safe at all: an unlocked, borrowed
     * laptop must not become a permanent takeover.
     */
    public function test_the_current_password_is_required_and_checked(): void
    {
        $user = $this->user();

        $this->actingAs($user)->put(route('password.update'), [
            'current_password'      => 'not-the-right-one',
            'password'              => 'lagos-9-ikoyi-terrace',
            'password_confirmation' => 'lagos-9-ikoyi-terrace',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('correct-horse-7-battery', $user->fresh()->password));
    }

    /** Same strength floor as registration — including the breach check. */
    public function test_a_weak_or_unconfirmed_password_is_refused(): void
    {
        $user = $this->user();

        $this->actingAs($user)->put(route('password.update'), [
            'current_password'      => 'correct-horse-7-battery',
            'password'              => 'short1',
            'password_confirmation' => 'short1',
        ])->assertSessionHasErrors('password');

        $this->actingAs($user)->put(route('password.update'), [
            'current_password'      => 'correct-horse-7-battery',
            'password'              => 'lagos-9-ikoyi-terrace',
            'password_confirmation' => 'something-else-entirely-2',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('correct-horse-7-battery', $user->fresh()->password));
    }

    /**
     * Re-entering the same password reads as success and changes nothing — the
     * worst outcome for somebody who believes they have just locked an intruder
     * out.
     */
    public function test_reusing_the_current_password_is_refused(): void
    {
        $user = $this->user();

        $this->actingAs($user)->put(route('password.update'), [
            'current_password'      => 'correct-horse-7-battery',
            'password'              => 'correct-horse-7-battery',
            'password_confirmation' => 'correct-horse-7-battery',
        ])->assertSessionHasErrors('password');
    }

    /**
     * The notification goes to the account, and its whole value is reaching
     * somebody who did not do this.
     */
    public function test_the_account_holder_is_told_their_password_changed(): void
    {
        Notification::fake();

        $user = $this->user();

        $this->actingAs($user)->put(route('password.update'), [
            'current_password'      => 'correct-horse-7-battery',
            'password'              => 'lagos-9-ikoyi-terrace',
            'password_confirmation' => 'lagos-9-ikoyi-terrace',
        ]);

        Notification::assertSentTo($user, PasswordChanged::class);
    }

    /** NFR-04: the audit trail records the fact, never the password. */
    public function test_the_change_is_audited_without_recording_the_password(): void
    {
        Notification::fake();

        $user = $this->user();

        $this->actingAs($user)->put(route('password.update'), [
            'current_password'      => 'correct-horse-7-battery',
            'password'              => 'lagos-9-ikoyi-terrace',
            'password_confirmation' => 'lagos-9-ikoyi-terrace',
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action'   => 'user.password_changed',
            'actor_id' => $user->id,
        ]);

        foreach (\DB::table('audit_events')->where('actor_id', $user->id)->get() as $event) {
            $this->assertStringNotContainsString('lagos-9-ikoyi-terrace', json_encode($event));
        }
    }

    public function test_a_guest_cannot_reach_it(): void
    {
        $this->get(route('password.edit'))->assertRedirect(route('login'));
        $this->put(route('password.update'), [])->assertRedirect(route('login'));
    }
}
