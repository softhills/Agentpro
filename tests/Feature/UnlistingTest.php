<?php

namespace Tests\Feature;

use App\Actions\UnlistListing;
use App\Enums\LifecycleState;
use App\Models\Area;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Coming off the market, and the archive it feeds (FR-M2-07, FR-M2-09).
 *
 * Two rules hold this together, and both are here because breaking either one
 * quietly is easy. The first is authority: a lister may report what happened to
 * their own property, and may not record a finding about it — those are
 * different powers wearing the same form. The second is that the public archive
 * is evidence. It exists to show a seeker that transactions complete on this
 * platform, so anything that lets a listing reach it without having been on the
 * market turns the one believable page on the site into the easiest one to fake.
 */
class UnlistingTest extends TestCase
{
    use RefreshDatabase;

    private function area(): Area
    {
        return Area::firstOrCreate(['slug' => 'lekki-phase-1'], [
            'name' => 'Lekki Phase 1', 'city' => 'Lagos', 'state' => 'Lagos',
            'is_scan_coverage' => true, 'centroid_lat' => 6.4478, 'centroid_lng' => 3.4723,
        ]);
    }

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
        return $this->user([
            'name' => 'Moderation Desk', 'category' => 'seeker',
            'is_staff' => true, 'staff_role' => 'moderator',
        ]);
    }

    private function listing(?User $lister = null, string $state = 'published', string $title = '3-Bed Apartment, Ikate'): Property
    {
        $lister ??= $this->user();
        $lat = 6.4441;
        $lng = 3.4795;

        $property = Property::create([
            'uuid' => Str::uuid(),
            'lister_id' => $lister->id,
            'area_id' => $this->area()->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'description' => 'Serviced three-bedroom apartment with 24-hour power.',
            'listing_type' => 'apartment',
            'intent' => 'rent',
            'build_status' => 'fully_built',
            'address_line' => 'Off Ikate Elegushi Road',
            'city' => 'Lagos', 'state' => 'Lagos',
            'lat' => $lat, 'lng' => $lng,
            'location' => DB::raw(sprintf("ST_GeomFromText('POINT(%F %F)')", $lng, $lat)),
            'lifecycle_state' => $state,
            'published_at' => $state === 'draft' ? null : now()->subDays(30),
            'expires_at' => $state === 'draft' ? null : now()->addDays(30),
        ]);

        Unit::create([
            'uuid' => Str::uuid(), 'property_id' => $property->id, 'is_primary' => true,
            'price' => 7500000, 'price_period' => 'year',
            'bedrooms' => 3, 'bathrooms' => 3, 'toilets' => 4, 'floor_area_sqm' => 142,
        ]);

        return $property->fresh();
    }

    // ------------------------------------------------------- the lister's own

    public function test_a_lister_marks_their_own_listing_rented(): void
    {
        $property = $this->listing();

        $this->actingAs($property->lister)
            ->post(route('lister.listings.unlist', $property), ['outcome' => 'rented'])
            ->assertRedirect(route('lister.dashboard'));

        $property->refresh();

        $this->assertSame(LifecycleState::Rented, $property->lifecycle_state);
        $this->assertNotNull($property->closed_at);
        $this->assertDatabaseHas('audit_events', [
            'action'     => 'listing.rented',
            'subject_id' => $property->id,
            'actor_id'   => $property->lister_id,
        ]);
    }

    /**
     * The outcome is the whole point of asking. Without it the archive is a
     * guess, so there is no default and no way past the question.
     */
    public function test_unlisting_without_an_outcome_is_refused(): void
    {
        $property = $this->listing();

        $this->actingAs($property->lister)
            ->post(route('lister.listings.unlist', $property), [])
            ->assertSessionHasErrors('outcome');

        $this->assertSame(LifecycleState::Published, $property->fresh()->lifecycle_state);
    }

    public function test_other_requires_a_reason_and_ends_as_unpublished(): void
    {
        $property = $this->listing();

        $this->actingAs($property->lister)
            ->post(route('lister.listings.unlist', $property), ['outcome' => 'other'])
            ->assertSessionHasErrors('reason_code');

        $this->actingAs($property->lister)->post(route('lister.listings.unlist', $property), [
            'outcome'     => 'other',
            'reason_code' => 'no_longer_available',
        ])->assertRedirect(route('lister.dashboard'));

        $property->refresh();

        $this->assertSame(LifecycleState::Unpublished, $property->lifecycle_state);
        $this->assertSame('no_longer_available', $property->rejection_reason_code);
    }

    /**
     * SEC-03. A finding — fraud, a title dispute, a report upheld — is
     * something the platform concludes about a listing. A lister who could
     * write one into their own audit trail would corrupt the only record that
     * says who decided what.
     */
    public function test_a_lister_cannot_stamp_a_moderators_reason_on_their_own_listing(): void
    {
        $property = $this->listing();

        $this->actingAs($property->lister)->post(route('lister.listings.unlist', $property), [
            'outcome'     => 'other',
            'reason_code' => 'suspected_fraud',
        ])->assertSessionHasErrors('reason_code');

        $this->assertSame(LifecycleState::Published, $property->fresh()->lifecycle_state);
    }

    public function test_a_lister_cannot_unlist_somebody_elses_listing(): void
    {
        $property = $this->listing();
        $stranger = $this->user(['name' => 'Someone Else']);

        $this->actingAs($stranger)
            ->post(route('lister.listings.unlist', $property), ['outcome' => 'sold'])
            ->assertForbidden();

        $this->assertSame(LifecycleState::Published, $property->fresh()->lifecycle_state);
    }

    public function test_a_draft_has_nothing_to_take_off_the_market(): void
    {
        $property = $this->listing(state: 'draft');

        $this->actingAs($property->lister)
            ->post(route('lister.listings.unlist', $property), ['outcome' => 'sold'])
            ->assertForbidden();
    }

    // ----------------------------------------------------------- putting back

    public function test_a_closing_the_lister_declared_can_be_undone(): void
    {
        $property = $this->listing();

        app(UnlistListing::class)->handle($property, $property->lister, 'sold');

        $this->actingAs($property->lister)
            ->post(route('lister.listings.relist', $property))
            ->assertRedirect(route('lister.dashboard'));

        $property->refresh();

        $this->assertSame(LifecycleState::Published, $property->lifecycle_state);
        $this->assertNull($property->closed_at);
    }

    /**
     * An unpublish is a decision somebody else made — usually a report or a
     * policy breach. Undoing that with a button would make the moderation power
     * meaningless; the way back is resubmission, through the queue.
     */
    public function test_an_unpublished_listing_cannot_be_relisted_by_its_lister(): void
    {
        $property = $this->listing();

        app(UnlistListing::class)->handle(
            $property, $this->moderator(), 'other', 'policy_breach'
        );

        $this->actingAs($property->lister)
            ->post(route('lister.listings.relist', $property))
            ->assertForbidden();

        $this->assertSame(LifecycleState::Unpublished, $property->fresh()->lifecycle_state);
    }

    /**
     * FR-M2-08. Relisting is an undo, not a renewal — otherwise "sold" becomes
     * the cheapest way to pause a display period somebody paid for.
     */
    public function test_relisting_does_not_hand_back_display_time(): void
    {
        $property = $this->listing();
        $property->update(['expires_at' => now()->subDay()]);

        app(UnlistListing::class)->handle($property, $property->lister, 'sold');
        app(UnlistListing::class)->relist($property->fresh(), $property->lister);

        $this->assertSame(LifecycleState::Expired, $property->fresh()->lifecycle_state);
    }

    // ----------------------------------------------------------------- admin

    public function test_an_admin_can_take_a_live_listing_down_and_put_it_back(): void
    {
        $property  = $this->listing();
        $moderator = $this->moderator();

        $this->actingAs($moderator)->post(route('admin.unlist', $property), [
            'outcome' => 'sold',
        ])->assertRedirect(route('admin.queue'));

        $this->assertSame(LifecycleState::Sold, $property->fresh()->lifecycle_state);

        $this->actingAs($moderator)
            ->post(route('admin.relist', $property))
            ->assertRedirect(route('admin.queue'));

        $this->assertSame(LifecycleState::Published, $property->fresh()->lifecycle_state);
    }

    /** SEC-03: the console does not confirm its own existence to an outsider. */
    public function test_an_ordinary_account_cannot_reach_the_admin_unlisting(): void
    {
        $property = $this->listing();

        $this->actingAs($this->user(['category' => 'seeker']))
            ->post(route('admin.unlist', $property), ['outcome' => 'sold'])
            ->assertNotFound();
    }

    /**
     * A moderator's policy `before()` clears every ability, so the state guard
     * cannot live only in the policy — the action has to refuse as well.
     */
    public function test_a_draft_is_refused_even_for_a_moderator(): void
    {
        $property = $this->listing(state: 'draft');

        $this->actingAs($this->moderator())
            ->post(route('admin.unlist', $property), ['outcome' => 'sold'])
            ->assertSessionHasErrors('outcome');

        $this->assertSame(LifecycleState::Draft, $property->fresh()->lifecycle_state);
    }

    // --------------------------------------------------------- the archive

    public function test_the_archive_is_public_and_shows_what_has_closed(): void
    {
        $sold   = $this->listing(title: '4-Bed Terrace, Ikoyi');
        $rented = $this->listing($sold->lister, title: '2-Bed Flat, Yaba');
        $live   = $this->listing($sold->lister, title: '3-Bed Flat, Victoria Island');

        app(UnlistListing::class)->handle($sold, $sold->lister, 'sold');
        app(UnlistListing::class)->handle($rented, $rented->lister, 'rented');

        // No actingAs: a seeker weighing up the platform has not signed up yet,
        // which is exactly when this page is worth something.
        $response = $this->get(route('pages.closed'))->assertOk();

        $response->assertSee('4-Bed Terrace, Ikoyi');
        $response->assertSee('2-Bed Flat, Yaba');
        $response->assertDontSee($live->title);

        // What the price on this page does and does not mean.
        $response->assertSee('does not hold the agreed', false);
    }

    public function test_the_archive_separates_sold_from_let(): void
    {
        $sold   = $this->listing(title: '4-Bed Terrace, Ikoyi');
        $rented = $this->listing($sold->lister, title: '2-Bed Flat, Yaba');

        app(UnlistListing::class)->handle($sold, $sold->lister, 'sold');
        app(UnlistListing::class)->handle($rented, $rented->lister, 'rented');

        $this->get(route('pages.closed', ['outcome' => 'sold']))
            ->assertOk()->assertSee('4-Bed Terrace, Ikoyi')->assertDontSee('2-Bed Flat, Yaba');

        $this->get(route('pages.closed', ['outcome' => 'rented']))
            ->assertOk()->assertSee('2-Bed Flat, Yaba')->assertDontSee('4-Bed Terrace, Ikoyi');
    }

    /**
     * The archive is evidence, so it only counts listings that were actually on
     * the market. Otherwise the cheapest way to look busy is to create drafts
     * and write 'sold' straight into the column.
     */
    public function test_a_listing_that_was_never_published_never_reaches_the_archive(): void
    {
        $property = $this->listing(state: 'draft', title: 'Never Was On The Market');
        $property->update(['lifecycle_state' => 'sold', 'closed_at' => now()]);

        $this->assertSame(0, Property::closedPublicly()->count());
        $this->get(route('pages.closed'))->assertOk()->assertDontSee('Never Was On The Market');
    }

    /** An unpublish ends a listing's public life; it is not a completion. */
    public function test_an_unpublished_listing_is_not_in_the_archive(): void
    {
        $property = $this->listing(title: 'Taken Down For Cause');

        app(UnlistListing::class)->handle(
            $property, $this->moderator(), 'other', 'upheld_report'
        );

        $this->get(route('pages.closed'))->assertOk()->assertDontSee('Taken Down For Cause');
    }

    /** FR-M2-09: closed, but still readable — the archive links to it. */
    public function test_a_closed_listing_keeps_its_public_page(): void
    {
        $property = $this->listing();

        app(UnlistListing::class)->handle($property, $property->lister, 'sold');

        $this->get(route('property.show', $property->fresh()))->assertOk();
    }

    /** Excluded from ordinary results unless the seeker asks for it. */
    public function test_a_closed_listing_leaves_the_default_search(): void
    {
        $property = $this->listing(title: 'Gone From Search');

        app(UnlistListing::class)->handle($property, $property->lister, 'sold');

        $this->get(route('search'))->assertOk()->assertDontSee('Gone From Search');
        $this->get(route('search', ['include_closed' => 1]))->assertOk()->assertSee('Gone From Search');
    }
}
