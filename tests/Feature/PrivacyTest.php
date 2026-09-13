<?php

namespace Tests\Feature;

use App\Actions\EraseAccount;
use App\Models\Area;
use App\Models\DataRequest;
use App\Models\Interaction;
use App\Models\Order;
use App\Models\Property;
use App\Models\SavedSearch;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\AccountDataReady;
use App\Notifications\AccountErasureScheduled;
use App\Support\Audit;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Access and erasure (FR-M1-09, NDPA 2023 ss. 34 and 38).
 *
 * Two rights, and the tests divide unevenly between them on purpose. The export
 * is mostly about what it must NOT contain — other people's details, and
 * secrets of ours — because a right-of-access feature that over-shares turns a
 * privacy obligation into a data breach.
 *
 * The erasure is almost entirely about the controls. The destructive part is a
 * handful of deletes; what earns the tests is that it waits, that it warns the
 * person it is happening to, that it refuses while somebody is still owed
 * something, and that it re-checks all of that at the moment it runs rather
 * than only when it was asked for.
 */
class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function seeker(array $attrs = []): User
    {
        return User::forceCreate(array_merge([
            'uuid' => Str::uuid(), 'name' => 'Amaka Obi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => bcrypt('correct-horse'), 'category' => 'seeker',
            'phone' => '08030000001',
        ], $attrs));
    }

    private function lister(array $attrs = []): User
    {
        return $this->seeker(array_merge([
            'name' => 'Tunde Adeyemi',
            'category' => 'sellers_agent',
            'verification_state' => 'verified',
            'verified_at' => now(),
        ], $attrs));
    }

    private function listing(User $lister, string $state = 'draft'): Property
    {
        $area = Area::firstOrCreate(['slug' => 'ikoyi'], [
            'name' => 'Ikoyi', 'city' => 'Lagos', 'state' => 'Lagos',
        ]);

        $property = Property::create([
            'uuid' => Str::uuid(), 'lister_id' => $lister->id, 'area_id' => $area->id,
            'title' => '3-Bed Apartment, Ikoyi', 'slug' => 'ikoyi-'.Str::lower(Str::random(6)),
            'listing_type' => 'apartment', 'intent' => 'rent', 'build_status' => 'fully_built',
            'address_line' => '12 Bourdillon Road', 'city' => 'Lagos', 'state' => 'Lagos',
            'lat' => 6.4488, 'lng' => 3.4390,
            'location' => DB::raw("ST_GeomFromText('POINT(3.4390 6.4488)')"),
            'lifecycle_state' => $state,
            'published_at' => $state === 'published' ? now() : null,
            'submitted_at' => in_array($state, ['submitted', 'under_review'], true) ? now() : null,
        ]);

        Unit::create([
            'uuid' => Str::uuid(), 'property_id' => $property->id, 'is_primary' => true,
            'price' => 7500000, 'price_period' => 'year', 'bedrooms' => 3,
        ]);

        return $property;
    }

    private function paidOrder(User $user): Order
    {
        return Order::create([
            'uuid' => Str::uuid(), 'user_id' => $user->id,
            'item_type' => 'scan_3d', 'amount' => 150000, 'currency' => 'NGN',
            'state' => 'paid', 'paid_at' => now()->subDays(2),
            'paystack_reference' => 'ref_'.Str::random(10),
        ]);
    }

    // ==================================================================== screen

    /**
     * The screen is the whole feature for anyone who is not a regulator, and a
     * Blade error on it would otherwise surface in a browser rather than here.
     */
    public function test_the_screen_says_what_is_kept_and_why_before_anything_is_asked_for(): void
    {
        $response = $this->actingAs($this->seeker())->get(route('privacy.index'));

        $response->assertOk()
            ->assertSee('Get a copy of your data')
            ->assertSee('Close your account')
            // The part a privacy screen usually leaves out.
            ->assertSee('Kept as it is')
            ->assertSee('Your statement')
            ->assertSee('six years');
    }

    public function test_the_screen_shows_what_has_to_be_settled_first(): void
    {
        $lister = $this->lister();
        $this->listing($lister, 'published');

        $this->actingAs($lister)->get(route('privacy.index'))
            ->assertOk()
            ->assertSee('still live', false)
            // No destructive form while it would be refused anyway.
            ->assertDontSee('CLOSE MY ACCOUNT');
    }

    public function test_a_scheduled_erasure_is_impossible_to_miss_on_the_screen(): void
    {
        Notification::fake();

        $user = $this->seeker();
        app(EraseAccount::class)->request($user, '105.112.0.1', 'Chrome on Android');

        $this->actingAs($user)->get(route('privacy.index'))
            ->assertOk()
            ->assertSee('scheduled to be closed')
            ->assertSee('Stop this')
            // Where it came from, so somebody who did not ask can tell at once.
            ->assertSee('105.112.0.1')
            ->assertSee('Chrome on Android');
    }

    public function test_the_screen_needs_an_account(): void
    {
        $this->get(route('privacy.index'))->assertRedirect(route('login'));
    }

    // ==================================================================== export

    public function test_asking_for_a_copy_builds_a_file_and_tells_the_person_out_of_band(): void
    {
        Notification::fake();
        Storage::fake('local');

        $user = $this->seeker();

        $this->actingAs($user)
            ->post(route('privacy.export'), ['password' => 'correct-horse'])
            ->assertRedirect();

        $request = DataRequest::where('user_id', $user->id)->where('kind', 'export')->firstOrFail();

        $this->assertSame('ready', $request->state);
        $this->assertTrue(Storage::disk('local')->exists($request->file_path));

        // The announcement is the control: a stolen session should not be able
        // to take a full copy of somebody's account without the owner hearing.
        Notification::assertSentTo($user, AccountDataReady::class);
    }

    public function test_a_copy_cannot_be_taken_without_the_password(): void
    {
        $user = $this->seeker();

        $this->actingAs($user)
            ->post(route('privacy.export'), ['password' => 'not-the-password'])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('data_requests', 0);
    }

    public function test_the_file_contains_what_the_person_actually_gave_us(): void
    {
        Storage::fake('local');

        $user = $this->seeker();

        SavedSearch::create([
            'user_id' => $user->id, 'name' => 'Ikoyi under 8m',
            'criteria' => ['area' => 'ikoyi', 'max_price' => 8000000],
            'frequency' => 'daily',
        ]);

        $this->paidOrder($user);

        $payload = $this->exportPayloadFor($user);

        $this->assertSame('Amaka Obi', $payload['account']['name']);
        $this->assertSame('Ikoyi under 8m', $payload['saved_searches'][0]['name']);
        $this->assertSame('ikoyi', $payload['saved_searches'][0]['looking_for']['area']);
        $this->assertCount(1, $payload['orders']);
        $this->assertSame('scan_3d', $payload['orders'][0]['for']);
    }

    /**
     * The failure that would turn this feature into a breach.
     *
     * A lister's export must not become a way to read the details of every
     * seeker who enquired about their listings. Those enquiries are the
     * seekers' data, and they have their own copy of it.
     */
    public function test_an_export_does_not_hand_over_another_persons_details(): void
    {
        Storage::fake('local');

        $lister = $this->lister();
        $property = $this->listing($lister, 'published');

        $seeker = $this->seeker(['name' => 'Chidi Nwosu', 'email' => 'chidi@example.test']);

        Interaction::create([
            'user_id' => $seeker->id, 'property_id' => $property->id,
            'kind' => 'contact', 'contact_mode' => 'phone',
        ]);

        $json = json_encode($this->exportPayloadFor($lister));

        $this->assertStringNotContainsString('Chidi Nwosu', $json);
        $this->assertStringNotContainsString('chidi@example.test', $json);

        // And the seeker's own copy does contain it, because it is theirs.
        $this->assertStringContainsString(
            'contact',
            json_encode($this->exportPayloadFor($seeker)['activity'])
        );
    }

    public function test_an_export_does_not_hand_over_our_own_secrets(): void
    {
        Storage::fake('local');

        $user = $this->seeker([
            'password' => bcrypt('correct-horse'),
            'verification_reference' => 'vendor-ref-9912',
            'verification_vendor' => 'verifyme',
            'remember_token' => 'remember-me-token',
        ]);

        $json = json_encode($this->exportPayloadFor($user));

        // A password hash is offline-crackable and the vendor reference is a
        // key into somebody else's system. Neither is a fact about the person.
        $this->assertStringNotContainsString('vendor-ref-9912', $json);
        $this->assertStringNotContainsString('remember-me-token', $json);
        $this->assertStringNotContainsString($user->password, $json);

        // The vendor's name is not a secret, and is worth knowing.
        $this->assertSame('verifyme', json_decode($json, true)['account']['identity_check']['checked_by']);
    }

    public function test_a_bank_account_number_is_masked_even_in_the_export(): void
    {
        Storage::fake('local');

        $lister = $this->lister();

        DB::table('payout_accounts')->insert([
            'user_id' => $lister->id, 'bank_code' => '058', 'bank_name' => 'GTBank',
            'account_number' => '0123456789', 'account_name' => 'TUNDE ADEYEMI',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $payload = $this->exportPayloadFor($lister);

        // This file travels — to a laptop, a phone, sometimes an inbox. A full
        // account number in it buys an attacker more than it tells the owner,
        // who knows their own number already.
        $this->assertSame('******6789', $payload['bank_accounts'][0]['account']);
    }

    public function test_a_file_belonging_to_someone_else_is_not_findable(): void
    {
        Storage::fake('local');

        $mine = $this->seeker();
        $theirs = $this->seeker();

        $this->actingAs($theirs)->post(route('privacy.export'), ['password' => 'correct-horse']);
        $request = DataRequest::where('user_id', $theirs->id)->firstOrFail();

        // 404 rather than 403: the difference between the two responses would
        // be a way to count other people's requests.
        $this->actingAs($mine)
            ->get(route('privacy.download', $request))
            ->assertNotFound();
    }

    public function test_an_expired_file_is_gone_rather_than_stale(): void
    {
        Storage::fake('local');

        $user = $this->seeker();
        $this->actingAs($user)->post(route('privacy.export'), ['password' => 'correct-horse']);

        $request = DataRequest::where('user_id', $user->id)->firstOrFail();
        $request->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($user)->get(route('privacy.download', $request))->assertStatus(410);

        $this->artisan('agentpro:run-data-requests')->assertSuccessful();

        $this->assertFalse(Storage::disk('local')->exists($request->file_path));
        $this->assertSame('expired', $request->fresh()->state);
        $this->assertNull($request->fresh()->file_path);
    }

    // =================================================================== erasure

    /**
     * An export that ages out while still marked `ready` must not count as one
     * still in progress. Otherwise the screen describes a deleted file as being
     * prepared, and asking again is refused because one is supposedly already
     * on its way — a statutory request stuck behind a stale row.
     */
    public function test_an_expired_copy_does_not_block_asking_for_another(): void
    {
        Storage::fake('local');

        $user = $this->seeker();
        $this->actingAs($user)->post(route('privacy.export'), ['password' => 'correct-horse']);

        DataRequest::where('user_id', $user->id)->update(['expires_at' => now()->subHour()]);

        $this->actingAs($user)->get(route('privacy.index'))
            ->assertOk()
            ->assertSee('Request a copy')
            ->assertDontSee('Still being prepared')
            ->assertDontSee('Download');

        $this->actingAs($user)
            ->post(route('privacy.export'), ['password' => 'correct-horse'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, DataRequest::where('user_id', $user->id)->where('kind', 'export')->count());
    }

    public function test_asking_to_be_erased_deletes_nothing_yet_and_warns_the_person(): void
    {
        Notification::fake();

        $user = $this->seeker();

        $this->actingAs($user)->post(route('privacy.erase'), [
            'password' => 'correct-horse',
            'confirm'  => 'CLOSE MY ACCOUNT',
        ])->assertRedirect();

        $request = DataRequest::where('user_id', $user->id)->where('kind', 'erasure')->firstOrFail();

        $this->assertSame('pending', $request->state);
        $this->assertTrue($request->executes_at->isFuture());

        // Still entirely intact. The pause is the control.
        $this->assertNull($user->fresh()->anonymised_at);
        $this->assertSame('Amaka Obi', $user->fresh()->name);

        // To the details already on file — the message has to reach the person
        // being harmed, not whoever asked.
        Notification::assertSentTo($user, AccountErasureScheduled::class);
    }

    public function test_erasure_needs_the_password_and_the_typed_confirmation(): void
    {
        $user = $this->seeker();

        $this->actingAs($user)->post(route('privacy.erase'), [
            'password' => 'correct-horse', 'confirm' => 'yes',
        ])->assertSessionHasErrors('confirm');

        $this->actingAs($user)->post(route('privacy.erase'), [
            'password' => 'wrong', 'confirm' => 'CLOSE MY ACCOUNT',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseCount('data_requests', 0);
    }

    public function test_a_scheduled_erasure_can_be_called_back_with_no_ceremony(): void
    {
        Notification::fake();

        $user = $this->seeker();
        $request = app(EraseAccount::class)->request($user);

        // No password, no confirmation. This is the escape hatch in an account
        // takeover, and friction on it is friction on the victim.
        $this->actingAs($user)
            ->post(route('privacy.cancel', $request))
            ->assertRedirect();

        $this->assertSame('cancelled', $request->fresh()->state);

        $this->travel(8)->days();
        $this->artisan('agentpro:run-data-requests')->assertSuccessful();

        $this->assertNull($user->fresh()->anonymised_at);
    }

    /**
     * The cooling-off period is the whole control, and `(int) config(...)` on a
     * missing key is zero — which does not weaken it, it removes it. This has a
     * concrete history on this project: a long-running queue worker held a copy
     * of the configuration from before the privacy block existed and wrote an
     * export that expired the same second it was built. The same stale read
     * against the erasure window would have deleted accounts with no warning
     * period at all.
     */
    public function test_a_missing_config_key_cannot_switch_off_the_cooling_off_period(): void
    {
        Notification::fake();

        config(['agentpro.privacy.erasure_grace_hours' => null]);

        $user = $this->seeker();
        $request = app(EraseAccount::class)->request($user);

        $this->assertTrue($request->executes_at->gt(now()->addHours(11)),
            'A missing or zero grace period must fall back to a floor, not to "delete immediately".');

        $this->travel(6)->hours();
        $this->artisan('agentpro:run-data-requests')->assertSuccessful();

        $this->assertNull($user->fresh()->anonymised_at);
    }

    public function test_nothing_happens_before_the_cooling_off_period_ends(): void
    {
        Notification::fake();

        $user = $this->seeker();
        app(EraseAccount::class)->request($user);

        $this->travel((int) config('agentpro.privacy.erasure_grace_hours') - 1)->hours();
        $this->artisan('agentpro:run-data-requests')->assertSuccessful();

        $this->assertNull($user->fresh()->anonymised_at);
    }

    // ------------------------------------------------------------ what it does

    public function test_erasure_clears_what_is_only_theirs_and_keeps_what_money_depends_on(): void
    {
        Notification::fake();

        $lister = $this->lister();
        $property = $this->listing($lister, 'unpublished');
        $order = $this->paidOrder($lister);

        SavedSearch::create([
            'user_id' => $lister->id, 'name' => 'Ikoyi', 'criteria' => ['area' => 'ikoyi'],
        ]);
        Interaction::create([
            'user_id' => $lister->id, 'property_id' => $property->id, 'kind' => 'save',
        ]);
        DB::table('sessions')->insert([
            'id' => Str::random(20), 'user_id' => $lister->id, 'ip_address' => '105.112.0.1',
            'user_agent' => 'Chrome', 'payload' => 'x', 'last_activity' => time(),
        ]);
        DB::table('push_subscriptions')->insert([
            'user_id' => $lister->id, 'endpoint' => 'https://push.example/x',
            // The endpoint is too long to index, so the table keys on a hash of
            // it. Written out here rather than stubbed, because a fixture that
            // skips a required column is testing a table that does not exist.
            'endpoint_hash' => hash('sha256', 'https://push.example/x'),
            'p256dh' => Str::random(60), 'auth' => Str::random(20),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Ledger::record($lister, 'credit', 5000, 'listing_incentive', 'Launch incentive', null, $lister->id);
        Ledger::record($lister, 'debit', 5000, 'payout', 'Paid out', null, $lister->id);
        Audit::record('listing.published', $property, [], [], $lister->id);

        $this->erase($lister);

        // Gone.
        $this->assertDatabaseCount('saved_searches', 0);
        $this->assertDatabaseCount('interactions', 0);
        $this->assertDatabaseCount('push_subscriptions', 0);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $lister->id)->count());

        // Kept — a tax record, a statement and an audit trail do not become
        // optional because somebody closed their account.
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'state' => 'paid']);
        $this->assertSame(2, DB::table('ledger_entries')->where('user_id', $lister->id)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'listing.published')->count());
        $this->assertDatabaseHas('properties', ['id' => $property->id]);
    }

    public function test_the_account_row_survives_with_nothing_personal_on_it(): void
    {
        Notification::fake();

        $lister = $this->lister(['phone' => '08031234567', 'commute_label' => 'Victoria Island']);
        $order = $this->paidOrder($lister);

        $this->erase($lister);

        $erased = User::withTrashed()->find($lister->id);

        $this->assertNotNull($erased, 'The row has to survive: orders and the audit trail point at it.');
        $this->assertNotNull($erased->anonymised_at);
        $this->assertNotNull($erased->deleted_at);

        $this->assertSame('Deleted account', $erased->name);
        $this->assertNull($erased->phone);
        $this->assertNull($erased->commute_label);
        $this->assertNull($erased->verification_reference);
        $this->assertNull($erased->notification_preferences);

        // .invalid is reserved by RFC 2606, so the placeholder that keeps the
        // unique index satisfied can never become a deliverable address.
        $this->assertStringEndsWith('@accounts.invalid', $erased->email);

        // And the order still resolves to it rather than dangling.
        $this->assertSame($erased->id, Order::find($order->id)->user_id);
    }

    public function test_the_old_password_no_longer_opens_the_account(): void
    {
        Notification::fake();

        $user = $this->seeker();
        $this->erase($user);

        $this->post(route('login'), [
            'email' => $user->email, 'password' => 'correct-horse',
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }

    public function test_a_bank_account_keeps_only_its_last_four_digits(): void
    {
        Notification::fake();

        $lister = $this->lister();

        DB::table('payout_accounts')->insert([
            'user_id' => $lister->id, 'bank_code' => '058', 'bank_name' => 'GTBank',
            'account_number' => '0123456789', 'account_name' => 'TUNDE ADEYEMI',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->erase($lister);

        $account = DB::table('payout_accounts')->where('user_id', $lister->id)->first();

        // Kept at all only so a past payout can still be shown to have gone
        // where it went. The bank name stays; the number does not.
        $this->assertSame('GTBank', $account->bank_name);
        $this->assertStringEndsWith('6789', $account->account_number);
        $this->assertStringNotContainsString('012345', $account->account_number);
        $this->assertSame('Deleted account', $account->account_name);
    }

    /**
     * An export is a complete copy of everything the person just asked to have
     * erased. Leaving one on disk would undo the erasure at the first URL
     * somebody still had.
     */
    public function test_erasure_destroys_any_export_file_still_sitting_on_disk(): void
    {
        Notification::fake();
        Storage::fake('local');

        $user = $this->seeker();

        $this->actingAs($user)->post(route('privacy.export'), ['password' => 'correct-horse']);
        $export = DataRequest::where('user_id', $user->id)->where('kind', 'export')->firstOrFail();

        $this->assertTrue(Storage::disk('local')->exists($export->file_path));

        $this->erase($user);

        $this->assertFalse(Storage::disk('local')->exists($export->file_path));
        $this->assertNull($export->fresh()->file_path);
    }

    // ----------------------------------------------------------- what refuses it

    public function test_a_live_listing_has_to_come_down_first(): void
    {
        $lister = $this->lister();
        $this->listing($lister, 'published');

        $blockers = app(EraseAccount::class)->blockers($lister);

        $this->assertNotEmpty($blockers);

        // Read aloud, not just matched on a substring: this sentence is the
        // entire explanation somebody gets for why the button is missing, and
        // "4 of your listings is still live" undermines it.
        $this->assertSame(
            'One of your listings is still live or waiting for review. Unpublish or withdraw '
            .'it first — seekers cannot be left with a listing nobody can answer for.',
            $blockers[0]
        );

        $this->listing($lister, 'submitted');
        $this->listing($lister, 'under_review');

        $this->assertStringStartsWith(
            '3 of your listings are still live or waiting for review.',
            app(EraseAccount::class)->blockers($lister)[0]
        );
    }

    public function test_money_still_owed_has_to_be_withdrawn_first(): void
    {
        $lister = $this->lister();
        Ledger::record($lister, 'credit', 12000, 'listing_incentive', 'Launch incentive', null, $lister->id);

        $blockers = app(EraseAccount::class)->blockers($lister);

        // Not a technical obstacle — closing the account here would quietly
        // extinguish an obligation in our favour.
        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('12,000', $blockers[0]);
    }

    public function test_a_paid_capture_that_has_not_happened_has_to_finish_first(): void
    {
        $lister = $this->lister();
        $property = $this->listing($lister, 'unpublished');
        $order = $this->paidOrder($lister);

        DB::table('scan_jobs')->insert([
            'uuid' => Str::uuid(), 'property_id' => $property->id, 'order_id' => $order->id,
            'state' => 'scheduled', 'scheduled_for' => now()->addDays(2),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $blockers = app(EraseAccount::class)->blockers($lister);

        $this->assertStringContainsString('3D capture', $blockers[0]);
    }

    /**
     * Removing a moderator or a finance admin is an offboarding — access
     * withdrawn, open decisions handed over. None of that belongs behind a
     * button on somebody's own settings page.
     */
    public function test_a_staff_account_cannot_close_itself(): void
    {
        $staff = $this->lister(['is_staff' => true, 'staff_role' => 'moderator']);

        $blockers = app(EraseAccount::class)->blockers($staff);

        $this->assertStringContainsString('staff access', $blockers[0]);
    }

    /**
     * The one this design exists for.
     *
     * Three days is long enough for a listing to be republished or a payout to
     * be requested. Checking only at request time would destroy the record of
     * an obligation that did not exist when the button was pressed.
     */
    public function test_blockers_are_checked_again_at_the_moment_it_runs(): void
    {
        Notification::fake();

        $lister = $this->lister();
        $property = $this->listing($lister, 'draft');

        $request = app(EraseAccount::class)->request($lister);
        $this->assertSame('pending', $request->state);

        // Something changes during the cooling-off period.
        $property->update(['lifecycle_state' => 'published', 'published_at' => now()]);

        $this->travel((int) config('agentpro.privacy.erasure_grace_hours') + 1)->hours();
        $this->artisan('agentpro:run-data-requests')->assertSuccessful();

        $this->assertSame('refused', $request->fresh()->state);
        $this->assertStringContainsString('still live', $request->fresh()->note);

        // Refused, not silently dropped — and nothing was destroyed on the way.
        $this->assertNull($lister->fresh()->anonymised_at);
        $this->assertNotNull($request->fresh()->note);
    }

    public function test_the_record_of_the_request_outlives_the_person_it_was_for(): void
    {
        Notification::fake();

        $user = $this->seeker();
        $request = $this->erase($user);

        // The only proof the right was honoured. A record of erasure that
        // erased itself would leave nothing to show for it.
        $this->assertSame('completed', $request->fresh()->state);
        $this->assertNotNull($request->fresh()->completed_at);
        $this->assertSame($user->id, $request->fresh()->user_id);
        $this->assertSame('Deleted account', $request->fresh()->user->name);
    }

    // ------------------------------------------------------------------ helpers

    /** Schedule an erasure and run it, as the scheduled command would. */
    private function erase(User $user): DataRequest
    {
        $request = app(EraseAccount::class)->request($user);

        $this->travel((int) config('agentpro.privacy.erasure_grace_hours') + 1)->hours();
        $this->artisan('agentpro:run-data-requests')->assertSuccessful();

        return $request;
    }

    /** @return array<string, mixed> */
    private function exportPayloadFor(User $user): array
    {
        return app(\App\Actions\ExportAccountData::class)->build($user);
    }
}
