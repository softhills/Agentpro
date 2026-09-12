<?php

namespace Tests\Feature;

use App\Actions\ModerateListing;
use App\Actions\RecordListingChange;
use App\Enums\LifecycleState;
use App\Models\Area;
use App\Models\Interaction;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\ListingApproved;
use App\Notifications\ListingReturned;
use App\Notifications\ListingUpdated;
use App\Support\NotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Notifications (M9).
 *
 * The two things worth protecting: that people only get what they asked for,
 * and that a material change actually reaches the people who asked for it.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        return User::forceCreate(array_merge([
            'uuid' => Str::uuid(),
            'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x',
            'category' => 'sellers_agent',
            'verification_state' => 'verified',
            'verified_at' => now(),
        ], $attrs));
    }

    private function moderator(): User
    {
        return $this->user(['category' => 'seeker', 'is_staff' => true, 'staff_role' => 'moderator']);
    }

    private function property(?User $lister = null): Property
    {
        $lister ??= $this->user();
        $area = Area::firstOrCreate(['slug' => 'ikoyi'], [
            'name' => 'Ikoyi', 'city' => 'Lagos', 'state' => 'Lagos', 'is_scan_coverage' => true,
        ]);

        $property = Property::create([
            'uuid' => Str::uuid(), 'lister_id' => $lister->id, 'area_id' => $area->id,
            'title' => '3-Bed Apartment, Ikoyi', 'slug' => 'ikoyi-'.Str::lower(Str::random(6)),
            'listing_type' => 'apartment', 'intent' => 'rent', 'build_status' => 'fully_built',
            'address_line' => '12 Bourdillon Road', 'city' => 'Lagos', 'state' => 'Lagos',
            'lat' => 6.4488, 'lng' => 3.4390,
            'location' => DB::raw("ST_GeomFromText('POINT(3.4390 6.4488)')"),
            'lifecycle_state' => LifecycleState::Submitted->value, 'submitted_at' => now(),
        ]);

        Unit::create([
            'uuid' => Str::uuid(), 'property_id' => $property->id, 'is_primary' => true,
            'price' => 7500000, 'price_period' => 'year', 'bedrooms' => 3,
        ]);

        return $property->fresh();
    }

    // ------------------------------------------------------------- preferences

    /** The overnight window is the case the naive comparison gets backwards. */
    public function test_quiet_hours_wrap_past_midnight(): void
    {
        $preferences = new NotificationPreferences([
            'quiet_enabled' => true, 'quiet_from' => '21:00', 'quiet_to' => '07:00',
        ]);

        $this->assertTrue($preferences->isQuietAt(Carbon::parse('23:30')), 'late evening is quiet');
        $this->assertTrue($preferences->isQuietAt(Carbon::parse('02:00')), 'after midnight is still quiet');
        $this->assertTrue($preferences->isQuietAt(Carbon::parse('06:59')), 'just before the end is quiet');
        $this->assertFalse($preferences->isQuietAt(Carbon::parse('07:00')), 'the end is exclusive');
        $this->assertFalse($preferences->isQuietAt(Carbon::parse('14:00')), 'the afternoon is not quiet');
        $this->assertFalse($preferences->isQuietAt(Carbon::parse('20:59')), 'just before the start is not quiet');
    }

    public function test_a_same_day_quiet_window_also_works(): void
    {
        $preferences = new NotificationPreferences([
            'quiet_enabled' => true, 'quiet_from' => '09:00', 'quiet_to' => '17:00',
        ]);

        $this->assertTrue($preferences->isQuietAt(Carbon::parse('12:00')));
        $this->assertFalse($preferences->isQuietAt(Carbon::parse('08:00')));
        $this->assertFalse($preferences->isQuietAt(Carbon::parse('22:00')));
    }

    public function test_quiet_hours_hold_back_push_but_never_the_inbox(): void
    {
        $preferences = new NotificationPreferences([
            'push' => true, 'email' => true, 'whatsapp' => true,
            'quiet_enabled' => true, 'quiet_from' => '21:00', 'quiet_to' => '07:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-12 23:00:00'));

        $channels = $preferences->resolve(['push', 'email', 'whatsapp']);

        $this->assertNotContains('push', $channels, 'push should wait until morning');
        $this->assertNotContains('whatsapp', $channels);
        $this->assertContains('email', $channels, 'email waits to be read, so it still goes');

        // Urgent bypasses the window entirely.
        $this->assertContains('push', $preferences->resolve(['push'], urgent: true));

        Carbon::setTestNow();
    }

    public function test_whatsapp_and_sms_are_off_unless_chosen(): void
    {
        $preferences = new NotificationPreferences(null);

        $this->assertTrue($preferences->enabled('email'));
        $this->assertTrue($preferences->enabled('push'));
        $this->assertFalse($preferences->enabled('whatsapp'), 'costs money and is intrusive');
        $this->assertFalse($preferences->enabled('sms'));
    }

    // --------------------------------------------------------- channel wiring

    /**
     * Every channel a notification can route to must be resolvable.
     *
     * Notification::fake() never resolves channels, so an unbound dependency in
     * one of them is invisible to every other test in this file. That is not
     * hypothetical: WhatsAppSender was unbound, and the failure surfaced only
     * when a real send ran — after the email had already gone out, leaving a
     * half-delivered notification in failed_jobs.
     */
    public function test_every_channel_a_notification_can_use_is_resolvable(): void
    {
        $user = $this->user();
        $user->update(['notification_preferences' => [
            'email' => true, 'push' => true, 'whatsapp' => true, 'sms' => true,
            'quiet_enabled' => false,
        ]]);

        $property = $this->property($user);

        $notifications = [
            new ListingApproved($property),
            new ListingReturned($property, 'A note.'),
            new ListingUpdated($property, ['The price has dropped.']),
            new \App\Notifications\VerificationDecided('verified'),
        ];

        foreach ($notifications as $notification) {
            foreach ($notification->via($user) as $channel) {
                // 'database' and 'mail' are framework drivers; anything else is
                // a class we own and must be able to build.
                if (class_exists($channel)) {
                    $this->assertNotNull(
                        app($channel),
                        $channel.' could not be resolved from the container'
                    );
                }
            }
        }
    }

    public function test_a_whatsapp_notification_reaches_the_sender(): void
    {
        // A single spy instance, bound by handle. An arrow function would
        // capture an array by value and the assertions would read a copy.
        $spy = new class implements \App\Services\Messaging\WhatsAppSender
        {
            public array $sent = [];

            public function sendTemplate(string $toPhone, string $template, array $variables): bool
            {
                $this->sent[] = ['to' => $toPhone, 'template' => $template, 'variables' => $variables];

                return true;
            }
        };

        $this->app->instance(\App\Services\Messaging\WhatsAppSender::class, $spy);

        $user = $this->user(['phone' => '+2348030000001']);
        $user->update(['notification_preferences' => [
            'whatsapp' => true, 'email' => false, 'push' => false, 'quiet_enabled' => false,
        ]]);

        $property = $this->property($user);

        app(\App\Channels\WhatsAppChannel::class)->send($user, new ListingApproved($property));

        $this->assertCount(1, $spy->sent);
        $this->assertSame('listing_approved', $spy->sent[0]['template']);
        $this->assertSame('+2348030000001', $spy->sent[0]['to']);
        // FR-M9-08: templates carry variables, not prose.
        $this->assertArrayHasKey('listing', $spy->sent[0]['variables']);
    }

    /** FR-M9-07: every email carries a way out of it. */
    public function test_every_email_carries_an_unsubscribe_route(): void
    {
        $user = $this->user();
        $user->update(['notification_preferences' => ['email' => true, 'quiet_enabled' => false]]);
        $property = $this->property($user);

        $emails = [
            new ListingApproved($property),
            new ListingReturned($property, 'A note.'),
            new ListingUpdated($property, ['The price has dropped.']),
            new \App\Notifications\VerificationDecided('verified'),
            new \App\Notifications\VerificationDecided('rejected', 'No match.'),
        ];

        foreach ($emails as $notification) {
            $rendered = (string) $notification->toMail($user)->render();

            $this->assertTrue(
                str_contains($rendered, '/unsubscribe/') || str_contains($rendered, route('notifications.edit')),
                class_basename($notification).' has no unsubscribe or settings link'
            );
        }
    }

    /** A category-scoped alert gets a true one-tap kill, not just a settings link. */
    public function test_a_listing_alert_email_carries_a_signed_one_tap_unsubscribe(): void
    {
        $user = $this->user(['category' => 'seeker']);
        $property = $this->property();

        $rendered = (string) (new ListingUpdated($property, ['The price has dropped.']))
            ->toMail($user)->render();

        $this->assertStringContainsString('/unsubscribe/'.$user->id.'/listing_updates', $rendered);
        $this->assertStringContainsString('signature=', $rendered, 'the link must be signed');
    }

    /** No number, no WhatsApp — and no exception either. */
    public function test_whatsapp_is_skipped_when_the_user_has_no_number(): void
    {
        $user = $this->user(['phone' => null]);
        $property = $this->property($user);

        app(\App\Channels\WhatsAppChannel::class)->send($user, new ListingApproved($property));

        $this->assertTrue(true, 'sending without a number must not throw');
    }

    // ------------------------------------------------------------ flow coverage

    public function test_approval_and_rejection_notify_the_lister(): void
    {
        Notification::fake();

        $property = $this->property();
        app(ModerateListing::class)->approve($property, $this->moderator());
        Notification::assertSentTo($property->lister, ListingApproved::class);

        $other = $this->property();
        app(ModerateListing::class)->reject($other, $this->moderator(), 'poor_media', 'Photographs are of a different property.');

        Notification::assertSentTo(
            $other->lister,
            ListingReturned::class,
            fn (ListingReturned $n) => str_contains($n->note, 'different property')
        );
    }

    // ----------------------------------------------------------- listing alerts

    /** FR-M9-04: saving is interest; viewing is not. */
    public function test_only_interacting_users_are_alerted_and_never_the_lister(): void
    {
        Notification::fake();

        $property = $this->property();
        $saver    = $this->user(['category' => 'seeker']);
        $bystander = $this->user(['category' => 'seeker']);
        $hider    = $this->user(['category' => 'seeker']);

        Interaction::create(['user_id' => $saver->id, 'property_id' => $property->id, 'kind' => 'save']);
        Interaction::create(['user_id' => $hider->id, 'property_id' => $property->id, 'kind' => 'save']);
        Interaction::create(['user_id' => $hider->id, 'property_id' => $property->id, 'kind' => 'hide']);
        // The lister interacts with their own listing; they still must not be alerted.
        Interaction::create(['user_id' => $property->lister_id, 'property_id' => $property->id, 'kind' => 'save']);

        app(RecordListingChange::class)->fromPriceChange($property->units->first(), 8_000_000);

        DB::table('pending_listing_alerts')->update(['window_closes_at' => now()->subMinute()]);
        $this->artisan('agentpro:dispatch-listing-alerts')->assertSuccessful();

        Notification::assertSentTo($saver, ListingUpdated::class);
        Notification::assertNotSentTo($bystander, ListingUpdated::class);
        Notification::assertNotSentTo($hider, ListingUpdated::class);
        Notification::assertNotSentTo($property->lister, ListingUpdated::class);
    }

    /** FR-M9-06: four edits in one sitting are one message that names all four. */
    public function test_changes_are_batched_into_one_alert(): void
    {
        Notification::fake();

        $property = $this->property();
        $saver = $this->user(['category' => 'seeker']);
        Interaction::create(['user_id' => $saver->id, 'property_id' => $property->id, 'kind' => 'save']);

        $recorder = app(RecordListingChange::class);
        $unit = $property->units->first();

        $recorder->fromPriceChange($unit, 8_000_000);
        $recorder->fromNewMedia($property, 'tour_3d');
        $recorder->fromNewMedia($property, 'video');

        $this->assertSame(1, DB::table('pending_listing_alerts')->count(), 'one open window, not three');

        DB::table('pending_listing_alerts')->update(['window_closes_at' => now()->subMinute()]);
        $this->artisan('agentpro:dispatch-listing-alerts')->assertSuccessful();

        Notification::assertSentToTimes($saver, ListingUpdated::class, 1);

        Notification::assertSentTo($saver, ListingUpdated::class, function (ListingUpdated $n) {
            $all = implode(' ', $n->changes);

            return str_contains($all, 'price has dropped')
                && str_contains($all, '3D tour')
                && str_contains($all, 'walkthrough video');
        });
    }

    /** Cosmetic edits are the ones that would make people stop reading alerts. */
    public function test_a_cosmetic_edit_does_not_queue_an_alert(): void
    {
        $property = $this->property();

        // A description change arrives here as a property update with no
        // material field altered.
        app(RecordListingChange::class)->fromPropertyUpdate($property, [
            'lifecycle_state' => $property->lifecycle_state->value,
        ]);

        $this->assertSame(0, DB::table('pending_listing_alerts')->count());
    }

    public function test_an_alert_batch_is_only_dispatched_once(): void
    {
        Notification::fake();

        $property = $this->property();
        $saver = $this->user(['category' => 'seeker']);
        Interaction::create(['user_id' => $saver->id, 'property_id' => $property->id, 'kind' => 'save']);

        app(RecordListingChange::class)->fromPriceChange($property->units->first(), 8_000_000);
        DB::table('pending_listing_alerts')->update(['window_closes_at' => now()->subMinute()]);

        $this->artisan('agentpro:dispatch-listing-alerts');
        $this->artisan('agentpro:dispatch-listing-alerts');

        Notification::assertSentToTimes($saver, ListingUpdated::class, 1);
    }

    /** FR-M9-07: switching the category off stops the alert, not everything. */
    public function test_a_seeker_who_switched_listing_updates_off_is_not_alerted(): void
    {
        Notification::fake();

        $property = $this->property();
        $saver = $this->user(['category' => 'seeker']);
        $saver->update(['notification_preferences' => ['listing_updates' => false]]);

        Interaction::create(['user_id' => $saver->id, 'property_id' => $property->id, 'kind' => 'save']);

        app(RecordListingChange::class)->fromPriceChange($property->units->first(), 8_000_000);
        DB::table('pending_listing_alerts')->update(['window_closes_at' => now()->subMinute()]);
        $this->artisan('agentpro:dispatch-listing-alerts');

        // The notification is still constructed and sent to the notifiable; it is
        // via() that resolves to no channels. Assert on the channels, which is
        // what actually determines whether anything is delivered.
        $notification = new ListingUpdated($property, ['The price has dropped.']);
        $this->assertSame([], $notification->via($saver));
    }

    // -------------------------------------------------------------- interactions

    public function test_saving_and_hiding_are_toggles(): void
    {
        $property = $this->property();
        $property->update(['lifecycle_state' => LifecycleState::Published->value, 'published_at' => now()]);
        $seeker = $this->user(['category' => 'seeker']);

        $this->actingAs($seeker)->post(route('interact.save', $property))->assertRedirect();
        $this->assertDatabaseHas('interactions', ['user_id' => $seeker->id, 'kind' => 'save']);

        $this->actingAs($seeker)->post(route('interact.save', $property))->assertRedirect();
        $this->assertDatabaseMissing('interactions', ['user_id' => $seeker->id, 'kind' => 'save']);
    }

    /** Saving something you previously hid is a change of mind. */
    public function test_saving_clears_a_previous_hide(): void
    {
        $property = $this->property();
        $seeker = $this->user(['category' => 'seeker']);

        $this->actingAs($seeker)->post(route('interact.hide', $property));
        $this->assertDatabaseHas('interactions', ['user_id' => $seeker->id, 'kind' => 'hide']);

        $this->actingAs($seeker)->post(route('interact.save', $property));

        $this->assertDatabaseHas('interactions', ['user_id' => $seeker->id, 'kind' => 'save']);
        $this->assertDatabaseMissing('interactions', ['user_id' => $seeker->id, 'kind' => 'hide']);
    }

    public function test_interactions_require_an_account(): void
    {
        $property = $this->property();

        $this->post(route('interact.save', $property))->assertRedirect(route('login'));
        $this->assertSame(0, Interaction::count());
    }

    // -------------------------------------------------------------- unsubscribe

    /** FR-M9-07: one tap, no sign-in, and not forgeable. */
    public function test_a_signed_unsubscribe_link_works_without_signing_in(): void
    {
        $user = $this->user();

        $url = \Illuminate\Support\Facades\URL::signedRoute('unsubscribe', [
            'user' => $user->id, 'category' => 'listing_updates',
        ]);

        $this->get($url)->assertOk()->assertSee('Unsubscribed');

        $this->assertFalse(NotificationPreferences::for($user->fresh())->wants('listing_updates'));
    }

    public function test_an_unsigned_unsubscribe_link_is_refused(): void
    {
        $user = $this->user();

        $this->get(route('unsubscribe', ['user' => $user->id, 'category' => 'all']))
            ->assertForbidden();

        $this->assertTrue(NotificationPreferences::for($user->fresh())->enabled('email'));
    }
}
