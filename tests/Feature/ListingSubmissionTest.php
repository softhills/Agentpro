<?php

namespace Tests\Feature;

use App\Actions\SubmitListingForReview;
use App\Enums\LifecycleState;
use App\Models\Amenity;
use App\Models\Area;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\TitleClaim;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The trust and money paths (PRD §17: "automated tests on the money and trust
 * paths; full coverage elsewhere is not worth the maintenance").
 *
 * Each test here corresponds to a rule the product's credibility rests on:
 * unverified listers cannot publish, a listing without a cost breakdown cannot
 * publish, and one lister cannot touch another's drafts.
 */
class ListingSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function area(): Area
    {
        return Area::create([
            'name' => 'Lekki Phase 1', 'slug' => 'lekki-phase-1',
            'city' => 'Lagos', 'state' => 'Lagos',
            'is_scan_coverage' => true,
            'centroid_lat' => 6.4478, 'centroid_lng' => 3.4723,
        ]);
    }

    private function lister(string $state = 'verified'): User
    {
        return User::forceCreate([
            'uuid' => Str::uuid(),
            'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(8)).'@example.test',
            'password' => 'password-not-used-here',
            'category' => 'sellers_agent',
            'verification_state' => $state,
            'verified_at' => $state === 'verified' ? now() : null,
        ]);
    }

    /** A listing complete enough to submit, minus whatever the test removes. */
    /**
     * A payload the update endpoint accepts, mirroring completeListing().
     *
     * The controller validates the whole listing on every save — units, fees
     * and titles included — so a test that only wants to change one field still
     * has to send a complete one. Built from the listing rather than from
     * constants, so this does not quietly start asserting a different property
     * than the one under test.
     *
     * @return array<string,mixed>
     */
    private function validPayload(Property $property): array
    {
        $unit = $property->headlineUnit();

        return [
            'title'        => $property->title,
            'description'  => $property->description,
            'listing_type' => $property->listing_type,
            'intent'       => $property->intent,
            'build_status' => $property->build_status,
            'area_id'      => $property->area_id,
            'address_line' => $property->address_line,
            'city'         => $property->city,
            'state'        => $property->state,
            'lat'          => $property->lat,
            'lng'          => $property->lng,
            'unit' => [
                'price'          => $unit->price,
                'price_period'   => $unit->price_period->value,
                'bedrooms'       => $unit->bedrooms,
                'bathrooms'      => $unit->bathrooms,
                'toilets'        => $unit->toilets,
                'floor_area_sqm' => $unit->floor_area_sqm,
            ],
            'fees' => $unit->feeLines->map(fn ($fee) => [
                'label'         => $fee->label,
                'amount'        => $fee->amount,
                'is_refundable' => $fee->is_refundable ? '1' : '0',
                'payee'         => $fee->payee,
            ])->all(),
            'titles' => $property->titleClaims
                ->mapWithKeys(fn ($claim) => [$claim->title_type => $claim->stage])->all(),
        ];
    }

    private function completeListing(User $lister): Property
    {
        $property = Property::create([
            'uuid' => Str::uuid(),
            'lister_id' => $lister->id,
            'area_id' => $this->area()->id,
            'title' => '3-Bed Apartment, Ikate',
            'slug' => 'three-bed-ikate-'.Str::lower(Str::random(5)),
            'description' => 'Serviced three-bedroom apartment with 24-hour power.',
            'listing_type' => 'apartment',
            'intent' => 'rent',
            'build_status' => 'fully_built',
            'finish' => 'furnished',
            'address_line' => 'Off Ikate Elegushi Road',
            'city' => 'Lagos',
            'state' => 'Lagos',
            'lat' => 6.4441,
            'lng' => 3.4795,
            'location' => DB::raw("ST_GeomFromText('POINT(3.4795 6.4441)')"),
            'lifecycle_state' => LifecycleState::Draft->value,
        ]);

        $unit = Unit::create([
            'uuid' => Str::uuid(),
            'property_id' => $property->id,
            'is_primary' => true,
            'price' => 7500000,
            'price_period' => 'year',
            'bedrooms' => 3, 'bathrooms' => 3, 'toilets' => 4,
            'floor_area_sqm' => 142,
        ]);

        $unit->feeLines()->create([
            'label' => 'Agency fee', 'amount' => 750000, 'calc_type' => 'fixed',
            'is_refundable' => false, 'payee' => 'Agent', 'sort_order' => 0,
        ]);
        $unit->feeLines()->create([
            'label' => 'Caution deposit', 'amount' => 750000, 'calc_type' => 'fixed',
            'is_refundable' => true, 'payee' => 'Landlord', 'sort_order' => 1,
        ]);

        TitleClaim::create([
            'property_id' => $property->id,
            'title_type' => 'certificate_of_occupancy',
            'stage' => 'available',
            'declared_at' => now(),
        ]);

        for ($i = 0; $i < config('agentpro.media.min_photos'); $i++) {
            MediaAsset::create([
                'uuid' => Str::uuid(),
                'property_id' => $property->id,
                'kind' => 'photo',
                'source' => 'lister',
                'moderation_state' => 'approved',
                'is_cover' => $i === 0,
                'sort_order' => $i,
            ]);
        }

        return $property->fresh(['units.feeLines', 'titleClaims', 'media', 'lister']);
    }

    public function test_a_complete_listing_can_be_submitted(): void
    {
        $property = $this->completeListing($this->lister());

        $this->assertSame([], app(SubmitListingForReview::class)->problems($property));

        $result = app(SubmitListingForReview::class)($property);

        $this->assertSame(LifecycleState::Submitted, $result->lifecycle_state);
        $this->assertNotNull($result->submitted_at);

        // FR-M12-05: the transition is on the audit trail.
        $this->assertDatabaseHas('audit_events', [
            'action' => 'listing.submitted',
            'subject_id' => $property->id,
        ]);
    }

    /** FR-M7-01 — the rule the fee-transparency proposition rests on. */
    public function test_a_listing_without_a_cost_breakdown_cannot_be_submitted(): void
    {
        $property = $this->completeListing($this->lister());
        $property->units->first()->feeLines()->delete();
        $property = $property->fresh(['units.feeLines', 'titleClaims', 'media', 'lister']);

        $problems = app(SubmitListingForReview::class)->problems($property);

        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('cost breakdown', implode(' ', $problems));

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SubmitListingForReview::class)($property);
    }

    /** FR-M1-05 — verification gates submission, not merely publication. */
    public function test_an_unverified_lister_cannot_submit(): void
    {
        $property = $this->completeListing($this->lister('pending'));

        $problems = app(SubmitListingForReview::class)->problems($property);

        $this->assertStringContainsString('identity must be verified', implode(' ', $problems));

        $this->actingAs($property->lister)
            ->post(route('lister.listings.submit', $property))
            ->assertForbidden();

        $this->assertSame(LifecycleState::Draft, $property->fresh()->lifecycle_state);
    }

    /**
     * Every chip in the section index points at a section that exists.
     *
     * An anchor to a missing id is the quietest possible bug: nothing errors,
     * nothing logs, the page simply does not move and the lister concludes the
     * navigation is broken. Renaming or removing a section is exactly the edit
     * that causes it, which is why this compares the two lists rather than
     * asserting a fixed set.
     */
    public function test_the_section_index_only_points_at_sections_that_exist(): void
    {
        $property = $this->completeListing($this->lister());

        $html = $this->actingAs($property->lister)
            ->get(route('lister.listings.edit', $property))
            ->assertOk()
            ->getContent();

        $nav = Str::before(Str::after($html, '<nav class="formnav"'), '</nav>');

        preg_match_all('/href="#([a-z-]+)"/', $nav, $targets);
        $this->assertNotEmpty($targets[1], 'The section index rendered no links at all.');

        foreach ($targets[1] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html, 'The section index links to #'.$id.', which is not on the page.');
        }
    }

    /**
     * Amenities still round-trip now that the section is a <details>.
     *
     * Folding it shut changes nothing about submission — the inputs are the
     * same inputs inside the same form, and a closed <details> posts exactly
     * what an open one does — but that is a claim worth having a test behind,
     * because the obvious worry about collapsing a form section is that its
     * fields stop being sent.
     */
    public function test_amenities_survive_being_in_a_collapsible_section(): void
    {
        $property = $this->completeListing($this->lister());

        $amenities = collect([
            ['name' => '24-hour power', 'slug' => '24-hour-power', 'category' => 'power'],
            ['name' => 'Treated borehole', 'slug' => 'treated-borehole', 'category' => 'water'],
        ])->map(fn ($row, $i) => Amenity::create($row + ['sort_order' => $i]))
          ->pluck('id')->all();

        $this->actingAs($property->lister)->put(
            route('lister.listings.update', $property),
            $this->validPayload($property) + ['amenities' => $amenities]
        )->assertRedirect();

        $this->assertEqualsCanonicalizing(
            $amenities,
            $property->fresh()->amenities->pluck('id')->all()
        );
    }

    /** SEC-03 — the uuid is an identifier, not a capability. */
    public function test_a_lister_cannot_edit_another_listers_draft(): void
    {
        $property = $this->completeListing($this->lister());
        $intruder = $this->lister();

        $this->actingAs($intruder)
            ->get(route('lister.listings.edit', $property))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->post(route('lister.listings.submit', $property))
            ->assertForbidden();
    }

    /** FR-M2-06 — a draft must 404 publicly, not merely be absent from search. */
    public function test_a_draft_is_not_publicly_reachable_by_uuid(): void
    {
        $property = $this->completeListing($this->lister());

        $this->get(route('property.show', $property))->assertNotFound();

        $property->update([
            'lifecycle_state' => LifecycleState::Published->value,
            'published_at' => now(),
        ]);

        $this->get(route('property.show', $property))->assertOk();
    }

    /** FR-M2-09 — closed listings stay findable, but only on request. */
    public function test_rented_listings_are_excluded_from_default_search(): void
    {
        $property = $this->completeListing($this->lister());
        $property->update([
            'lifecycle_state' => LifecycleState::Rented->value,
            'published_at' => now(),
        ]);

        // zoom 16 so the endpoint returns individual pins rather than clusters —
        // cluster markers deliberately carry no listing identity, so an
        // assertion about a specific uuid has to be made at pin zoom.
        $this->getJson(route('search.pins', ['zoom' => 16]))
            ->assertOk()
            ->assertJsonMissing(['id' => $property->uuid]);

        $this->getJson(route('search.pins', ['zoom' => 16, 'include_closed' => 1]))
            ->assertOk()
            ->assertJsonFragment(['id' => $property->uuid]);
    }

    /** FR-M7-02 — the number the whole listing page exists to produce. */
    public function test_move_in_total_sums_rent_and_fees_and_separates_refundables(): void
    {
        $unit = $this->completeListing($this->lister())->units->first();

        $this->assertEqualsWithDelta(9_000_000.0, $unit->moveInTotal(), 0.01);
        $this->assertEqualsWithDelta(750_000.0, $unit->refundableTotal(), 0.01);
        $this->assertEqualsWithDelta(8_250_000.0, $unit->nonRefundableTotal(), 0.01);
    }

    /** SEC-12 — an audit log that can be edited is not an audit log. */
    public function test_audit_events_cannot_be_altered_or_deleted(): void
    {
        app(SubmitListingForReview::class)($this->completeListing($this->lister()));

        $event = \App\Models\AuditEvent::firstOrFail();

        $this->expectException(\LogicException::class);
        $event->delete();
    }
}
