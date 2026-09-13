<?php

namespace Tests\Feature;

use App\Actions\RecordRealsureCheck;
use App\Models\Area;
use App\Models\AuditEvent;
use App\Models\Order;
use App\Models\Property;
use App\Models\RealsureRecord;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\RealsureDecided;
use App\Queries\RealsureQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The RealSure officer console (FR-M6-01 to FR-M6-03).
 *
 * The badge is the product's central claim: "Agentpro has conducted extra
 * verification on the property" is the sentence a buyer relies on when they
 * stop asking their own questions. Almost everything below is therefore about
 * what must NOT be able to happen — a badge nobody earned, a badge a lister
 * gave themselves, a badge that survives the check underneath it being
 * withdrawn, or one that cannot be taken off again.
 */
class RealsureTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return $this->staff('realsure_officer');
    }

    private function staff(string $role): User
    {
        return User::forceCreate([
            'uuid' => Str::uuid(), 'name' => ucfirst($role).' Desk',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'seeker',
            'is_staff' => true, 'staff_role' => $role,
            'verification_state' => 'verified',
        ]);
    }

    private function lister(): User
    {
        return User::forceCreate([
            'uuid' => Str::uuid(), 'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'sellers_agent',
            'verification_state' => 'verified', 'verified_at' => now(),
        ]);
    }

    private function listing(string $state = 'published', ?User $lister = null): Property
    {
        $area = Area::firstOrCreate(['slug' => 'ikoyi'], [
            'name' => 'Ikoyi', 'city' => 'Lagos', 'state' => 'Lagos',
        ]);

        $property = Property::create([
            'uuid' => Str::uuid(), 'lister_id' => ($lister ?? $this->lister())->id,
            'area_id' => $area->id,
            'title' => '3-Bed Apartment, Ikoyi', 'slug' => 'ikoyi-'.Str::lower(Str::random(6)),
            'listing_type' => 'apartment', 'intent' => 'sale', 'build_status' => 'fully_built',
            'address_line' => '12 Bourdillon Road', 'city' => 'Lagos', 'state' => 'Lagos',
            'lat' => 6.4488, 'lng' => 3.4390,
            'location' => DB::raw("ST_GeomFromText('POINT(3.4390 6.4488)')"),
            'lifecycle_state' => $state,
            'published_at' => $state === 'published' ? now()->subDays(5) : null,
        ]);

        Unit::create([
            'uuid' => Str::uuid(), 'property_id' => $property->id, 'is_primary' => true,
            'price' => 185000000, 'price_period' => 'once', 'bedrooms' => 3,
        ]);

        return $property;
    }

    /** Record the two checks the badge needs, so the happy path is one call. */
    private function verifyEnough(Property $property, User $officer): void
    {
        $checks = app(RecordRealsureCheck::class);
        $checks->record($property, 'title_verification', $officer, true);
        $checks->record($property->fresh(), 'search_report', $officer, true);
    }

    // ==================================================================== access

    public function test_the_console_is_invisible_to_everyone_without_the_role(): void
    {
        $property = $this->listing();

        // 404, not 403: the existence of the console is not something an
        // ordinary account needs confirmed.
        $this->actingAs($this->lister())->get(route('realsure.queue'))->assertNotFound();
        $this->actingAs($this->staff('technician'))->get(route('realsure.queue'))->assertNotFound();
        $this->actingAs($this->staff('moderator'))->get(route('realsure.record', $property))->assertNotFound();
    }

    /**
     * The bug this console was built on top of: the admin area is gated on the
     * moderator check, so before this an officer got a 404 on every screen —
     * including the one named after their job.
     */
    public function test_an_officer_can_reach_their_own_console(): void
    {
        $this->actingAs($this->officer())->get(route('realsure.queue'))
            ->assertOk()
            ->assertSee('RealSure');
    }

    public function test_an_admin_can_reach_it_too(): void
    {
        $this->actingAs($this->staff('admin'))->get(route('realsure.queue'))->assertOk();
    }

    public function test_the_sidebar_offers_an_officer_only_what_they_can_open(): void
    {
        $this->actingAs($this->officer())->get(route('realsure.queue'))
            ->assertOk()
            // A link to a 404 is worse than no link: it teaches whoever clicks
            // it that the console is broken.
            ->assertDontSee('Review queue')
            ->assertDontSee('Settlements')
            ->assertDontSee('Amenities &amp; areas', false);
    }

    // ================================================================= recording

    public function test_recording_a_check_stamps_the_date_and_the_officer(): void
    {
        $officer = $this->officer();
        $property = $this->listing();

        $this->actingAs($officer)->post(route('realsure.component', $property), [
            'component' => 'title_verification',
            'completed' => '1',
            'completed_on' => now()->subDays(3)->toDateString(),
            'evidence_ref' => 'Search report no. LS/2026/04481',
            'notes' => 'Root of title traced to the 1978 excision.',
        ])->assertRedirect();

        $record = RealsureRecord::where('property_id', $property->id)
            ->where('component', 'title_verification')->firstOrFail();

        $this->assertTrue($record->completed);
        $this->assertSame(now()->subDays(3)->toDateString(), $record->completed_on->toDateString());
        $this->assertSame($officer->id, $record->officer_id);
        $this->assertSame('Search report no. LS/2026/04481', $record->evidence_ref);

        // FR-M6-03: held in the audit log, which is what makes the badge
        // defensible a year later when somebody asks who said this.
        $this->assertDatabaseHas('audit_events', [
            'action' => 'realsure.component_recorded',
            'actor_id' => $officer->id,
            'subject_id' => $property->id,
        ]);
    }

    public function test_a_check_cannot_be_completed_in_the_future(): void
    {
        $property = $this->listing();

        $this->actingAs($this->officer())->post(route('realsure.component', $property), [
            'component' => 'valuation',
            'completed' => '1',
            'completed_on' => now()->addWeek()->toDateString(),
        ])->assertSessionHasErrors('completed_on');

        $this->assertDatabaseCount('realsure_records', 0);
    }

    public function test_withdrawing_a_check_clears_its_date_and_its_officer(): void
    {
        $officer = $this->officer();
        $property = $this->listing();
        $checks = app(RecordRealsureCheck::class);

        $checks->record($property, 'valuation', $officer, true);
        $checks->record($property->fresh(), 'valuation', $officer, false);

        $record = RealsureRecord::where('component', 'valuation')->firstOrFail();

        // A component marked "not commissioned" must not keep a completion date
        // from last month — the listing page shows that date to seekers.
        $this->assertFalse($record->completed);
        $this->assertNull($record->completed_on);
        $this->assertNull($record->officer_id);
    }

    public function test_an_invented_component_is_refused(): void
    {
        $property = $this->listing();

        $this->actingAs($this->officer())->post(route('realsure.component', $property), [
            'component' => 'vibes_check', 'completed' => '1',
        ])->assertSessionHasErrors('component');

        $this->assertDatabaseCount('realsure_records', 0);
    }

    // ==================================================================== badge

    public function test_photography_alone_does_not_earn_the_badge(): void
    {
        $officer = $this->officer();
        $property = $this->listing();
        $checks = app(RecordRealsureCheck::class);

        foreach (['hd_photography', 'drone_photography', 'floor_plans', 'immersive_capture'] as $c) {
            $checks->record($property->fresh(), $c, $officer, true);
        }

        // All four production components done, and the badge still cannot go
        // on: sending a photographer establishes nothing about the property.
        $blockers = $checks->blockers($property->fresh()->load('realsureRecords'));

        $this->assertNotEmpty($blockers);
        $this->expectException(RuntimeException::class);
        $checks->grant($property->fresh(), $officer);
    }

    public function test_the_badge_cannot_go_on_without_title_verification(): void
    {
        $officer = $this->officer();
        $property = $this->listing();
        $checks = app(RecordRealsureCheck::class);

        // Two verification checks — enough on count alone, but not the one the
        // standing disclaimer under every listing names by name.
        $checks->record($property->fresh(), 'search_report', $officer, true);
        $checks->record($property->fresh(), 'valuation', $officer, true);

        $blockers = $checks->blockers($property->fresh()->load('realsureRecords'));

        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('Title verification', $blockers[0]);
    }

    public function test_an_unpublished_listing_cannot_be_badged(): void
    {
        $officer = $this->officer();
        $property = $this->listing('draft');

        $this->verifyEnough($property, $officer);

        $blockers = app(RecordRealsureCheck::class)->blockers($property->fresh()->load('realsureRecords'));

        $this->assertStringContainsString('not published', $blockers[0]);
    }

    public function test_granting_lights_up_the_badge_and_tells_the_lister(): void
    {
        Notification::fake();

        $officer = $this->officer();
        $lister = $this->lister();
        $property = $this->listing('published', $lister);

        $this->verifyEnough($property, $officer);

        $this->actingAs($officer)->post(route('realsure.grant', $property))->assertRedirect();

        $this->assertTrue($property->fresh()->isRealsureVerified());

        $this->assertDatabaseHas('audit_events', [
            'action' => 'realsure.badge_granted',
            'actor_id' => $officer->id,
        ]);

        Notification::assertSentTo($lister, RealsureDecided::class);
    }

    /** FR-M6-01: never self-applied. */
    public function test_a_lister_cannot_badge_their_own_listing(): void
    {
        $lister = $this->lister();
        $property = $this->listing('published', $lister);

        $this->verifyEnough($property, $this->officer());

        $this->actingAs($lister)->post(route('realsure.grant', $property))->assertNotFound();

        $this->assertFalse($property->fresh()->isRealsureVerified());
    }

    public function test_the_badge_shows_on_the_public_listing_with_what_was_checked(): void
    {
        Notification::fake();

        $officer = $this->officer();
        $property = $this->listing();

        $this->verifyEnough($property, $officer);
        app(RecordRealsureCheck::class)->grant($property->fresh()->load('realsureRecords'), $officer);

        $this->get(route('property.show', $property))
            ->assertOk()
            ->assertSee('What RealSure checked')
            ->assertSee('Title verification')
            // FR-M6-02: an unchecked component is displayed, not hidden.
            // Knowing no valuation was commissioned is information too.
            ->assertSee('not commissioned');
    }

    // ================================================================ revocation

    public function test_the_badge_can_be_taken_off_with_a_reason(): void
    {
        Notification::fake();

        $officer = $this->officer();
        $lister = $this->lister();
        $property = $this->listing('published', $lister);

        $this->verifyEnough($property, $officer);
        app(RecordRealsureCheck::class)->grant($property->fresh()->load('realsureRecords'), $officer);

        $this->actingAs($officer)->post(route('realsure.revoke', $property), [
            'why' => 'The search report referred to the adjoining plot, not this one.',
        ])->assertRedirect();

        $this->assertFalse($property->fresh()->isRealsureVerified());

        // The listing stays live. Removing a trust mark is not a moderation
        // decision, and unpublishing somebody's listing over it would be.
        $this->assertSame('published', $property->fresh()->lifecycle_state->value);

        $this->assertDatabaseHas('audit_events', ['action' => 'realsure.badge_revoked']);

        $reason = AuditEvent::where('action', 'realsure.badge_revoked')->firstOrFail();
        $this->assertStringContainsString('adjoining plot', json_encode($reason->after));

        // They paid for this. A badge that vanishes without a word is how a
        // support ticket becomes a complaint.
        Notification::assertSentTo($lister, RealsureDecided::class);
    }

    public function test_a_revocation_needs_more_than_a_shrug(): void
    {
        Notification::fake();

        $officer = $this->officer();
        $property = $this->listing();

        $this->verifyEnough($property, $officer);
        app(RecordRealsureCheck::class)->grant($property->fresh()->load('realsureRecords'), $officer);

        $this->actingAs($officer)->post(route('realsure.revoke', $property), ['why' => 'nope'])
            ->assertSessionHasErrors('why');

        $this->assertTrue($property->fresh()->isRealsureVerified());
    }

    /**
     * The case worth the most: a badge must not outlive the check it rested on.
     * Leaving that to whoever remembers is how a listing ends up asserting
     * something nobody stands behind.
     */
    public function test_withdrawing_a_check_the_badge_rested_on_takes_the_badge_with_it(): void
    {
        Notification::fake();

        $officer = $this->officer();
        $property = $this->listing();

        $this->verifyEnough($property, $officer);
        app(RecordRealsureCheck::class)->grant($property->fresh()->load('realsureRecords'), $officer);
        $this->assertTrue($property->fresh()->isRealsureVerified());

        // The title check turns out to have been read wrong.
        app(RecordRealsureCheck::class)->record(
            $property->fresh()->load('realsureRecords'), 'title_verification', $officer, false
        );

        $this->assertFalse($property->fresh()->isRealsureVerified());
        $this->assertDatabaseHas('audit_events', ['action' => 'realsure.badge_revoked']);
    }

    public function test_withdrawing_a_check_the_badge_did_not_need_leaves_it_alone(): void
    {
        Notification::fake();

        $officer = $this->officer();
        $property = $this->listing();
        $checks = app(RecordRealsureCheck::class);

        $this->verifyEnough($property, $officer);
        $checks->record($property->fresh(), 'valuation', $officer, true);
        $checks->record($property->fresh(), 'hd_photography', $officer, true);
        $checks->grant($property->fresh()->load('realsureRecords'), $officer);

        // Photography was never load-bearing, and the two verification checks
        // underneath the badge are untouched.
        $checks->record($property->fresh()->load('realsureRecords'), 'hd_photography', $officer, false);

        $this->assertTrue($property->fresh()->isRealsureVerified());
    }

    // ===================================================================== queue

    /**
     * RealSure is sold as an engagement rather than through a checkout, so a
     * paid order with no badge is money taken for work somebody is still
     * waiting for. It must never sit at the bottom of a list.
     */
    public function test_work_that_has_been_paid_for_leads_the_queue(): void
    {
        $lister = $this->lister();
        $paidFor = $this->listing('published', $lister);
        $this->listing('published', $lister);

        Order::create([
            'uuid' => Str::uuid(), 'user_id' => $lister->id, 'property_id' => $paidFor->id,
            'item_type' => 'realsure', 'amount' => 350000, 'currency' => 'NGN',
            'state' => 'paid', 'paid_at' => now()->subDay(),
            'paystack_reference' => 'ref_'.Str::random(10),
        ]);

        $outstanding = (new RealsureQueue)->paidAndOutstanding();

        $this->assertCount(1, $outstanding);
        $this->assertSame($paidFor->id, $outstanding->first()->id);

        $this->actingAs($this->officer())->get(route('realsure.queue'))
            ->assertOk()
            ->assertSee('Paid for, not delivered');
    }

    public function test_a_badged_listing_leaves_the_outstanding_queue(): void
    {
        Notification::fake();

        $officer = $this->officer();
        $lister = $this->lister();
        $property = $this->listing('published', $lister);

        Order::create([
            'uuid' => Str::uuid(), 'user_id' => $lister->id, 'property_id' => $property->id,
            'item_type' => 'realsure', 'amount' => 350000, 'currency' => 'NGN',
            'state' => 'paid', 'paid_at' => now()->subDay(),
            'paystack_reference' => 'ref_'.Str::random(10),
        ]);

        $this->assertCount(1, (new RealsureQueue)->paidAndOutstanding());

        $this->verifyEnough($property, $officer);
        app(RecordRealsureCheck::class)->grant($property->fresh()->load('realsureRecords'), $officer);

        $this->assertCount(0, (new RealsureQueue)->paidAndOutstanding());
        $this->assertCount(1, (new RealsureQueue)->verified());
    }

    public function test_progress_separates_what_was_checked_from_what_was_produced(): void
    {
        $officer = $this->officer();
        $property = $this->listing();
        $checks = app(RecordRealsureCheck::class);

        $checks->record($property->fresh(), 'title_verification', $officer, true);
        $checks->record($property->fresh(), 'hd_photography', $officer, true);
        $checks->record($property->fresh(), 'drone_photography', $officer, true);

        $progress = RealsureQueue::progressFor($property->fresh()->load('realsureRecords'));

        // "3 of 10" would hide that only one of the three was a check.
        $this->assertSame(1, $progress['verification']);
        $this->assertSame(2, $progress['production']);
    }

    public function test_the_record_sheet_shows_all_ten_components_and_why_the_badge_is_blocked(): void
    {
        $property = $this->listing();

        \App\Models\TitleClaim::create([
            'property_id' => $property->id,
            'title_type' => 'certificate_of_occupancy',
            'stage' => 'in_progress',
            'declared_at' => now(),
        ]);

        $this->actingAs($this->officer())->get(route('realsure.record', $property))
            ->assertOk()
            ->assertSee('Title verification')
            ->assertSee('Valuation')
            ->assertSee('Drone photography')
            ->assertSee('Evidence reference')
            // What the lister actually claimed, so the officer does not have to
            // open another tab before checking it — and with the stage spelled
            // out rather than rendered as empty brackets.
            ->assertSee('Certificate of Occupancy')
            ->assertSee('in progress')
            // The officer is told what is standing in the way rather than
            // shown a button that silently does nothing.
            ->assertSee('Title verification has not been completed', false);
    }
}
