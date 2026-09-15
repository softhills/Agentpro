<?php

namespace Tests\Feature;

use App\Actions\ModerateListing;
use App\Actions\SubmitListingForReview;
use App\Enums\LifecycleState;
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
 * Moderation (M12) — the gate between a submission and the public.
 *
 * The rule these tests exist to protect: a listing becomes publicly visible
 * through exactly one path, and every change of visibility is attributable.
 */
class ModerationTest extends TestCase
{
    use RefreshDatabase;

    private function area(string $slug = 'lekki-phase-1'): Area
    {
        return Area::firstOrCreate(['slug' => $slug], [
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
            'name' => 'Moderation Desk',
            'category' => 'seeker',
            'is_staff' => true,
            'staff_role' => 'moderator',
        ]);
    }

    private function submittedListing(?User $lister = null, float $lat = 6.4441, float $lng = 3.4795, string $title = '3-Bed Apartment, Ikate'): Property
    {
        $lister ??= $this->user();

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
            'lifecycle_state' => LifecycleState::Draft->value,
        ]);

        $unit = Unit::create([
            'uuid' => Str::uuid(), 'property_id' => $property->id, 'is_primary' => true,
            'price' => 7500000, 'price_period' => 'year',
            'bedrooms' => 3, 'bathrooms' => 3, 'toilets' => 4, 'floor_area_sqm' => 142,
        ]);
        $unit->feeLines()->create([
            'label' => 'Agency fee', 'amount' => 750000, 'calc_type' => 'fixed', 'sort_order' => 0,
        ]);

        TitleClaim::create([
            'property_id' => $property->id, 'title_type' => 'certificate_of_occupancy',
            'stage' => 'available', 'declared_at' => now(),
        ]);

        for ($i = 0; $i < config('agentpro.media.min_photos'); $i++) {
            MediaAsset::create([
                'uuid' => Str::uuid(), 'property_id' => $property->id, 'kind' => 'photo',
                'source' => 'lister', 'moderation_state' => 'approved',
                'is_cover' => $i === 0, 'sort_order' => $i,
            ]);
        }

        return app(SubmitListingForReview::class)(
            $property->fresh(['units.feeLines', 'titleClaims', 'media', 'lister'])
        );
    }

    /**
     * FR-M12-02: the moderator can see the photographs they are judging.
     *
     * This strip rendered decorative placeholder artwork — not as a fallback
     * when an asset was missing, but hard-coded, for every listing. Nothing
     * errored and the screen looked complete, so listings were approved and
     * rejected against pictures of a generic building while one of the
     * rejection reasons on the same page reads "photographs missing, unusable
     * or not of this property".
     *
     * Asserting the real URL is present is the whole test: a placeholder is
     * indistinguishable from a photograph to anything except the src.
     */
    public function test_the_review_screen_shows_the_actual_photographs(): void
    {
        $property = $this->submittedListing();

        $photo = $property->media->where('kind', 'photo')->first();
        $photo->update([
            'disk'       => 'public',
            'path'       => 'media/'.$property->uuid.'/original.webp',
            'renditions' => [
                '400'  => 'media/'.$property->uuid.'/x-400.webp',
                '1600' => 'media/'.$property->uuid.'/x-1600.webp',
            ],
        ]);

        $response = $this->actingAs($this->moderator())
            ->get(route('admin.review', $property))
            ->assertOk();

        $response->assertSee('x-400.webp', false);
        // Opens full size: 88px of a room is enough to count photographs and
        // not enough to judge one.
        $response->assertSee('x-1600.webp', false);
    }

    /**
     * Every photograph, not the first eight.
     *
     * A listing may carry thirty, and the one that is a photograph of a
     * different building is not reliably among the first eight. A reviewer
     * shown a silent subset is worse off than one who knows it is a subset.
     */
    public function test_the_review_screen_does_not_hide_photographs_behind_a_cap(): void
    {
        $property = $this->submittedListing();

        foreach (range(1, 12) as $i) {
            MediaAsset::create([
                'uuid' => Str::uuid(), 'property_id' => $property->id, 'kind' => 'photo',
                'source' => 'lister', 'moderation_state' => 'approved',
                'sort_order' => 100 + $i,
                'disk' => 'public',
                'renditions' => ['400' => 'media/extra-'.$i.'-400.webp'],
            ]);
        }

        $html = $this->actingAs($this->moderator())
            ->get(route('admin.review', $property))
            ->assertOk()
            ->getContent();

        foreach (range(1, 12) as $i) {
            $this->assertStringContainsString('extra-'.$i.'-400.webp', $html);
        }
    }

    public function test_the_console_is_invisible_to_non_staff(): void
    {
        $property = $this->submittedListing();

        // 404 rather than 403 — an ordinary account does not need the existence
        // of the moderation console confirmed.
        $this->actingAs($this->user())->get(route('admin.queue'))->assertNotFound();
        $this->actingAs($this->user())->get(route('admin.review', $property))->assertNotFound();
        $this->actingAs($this->user())
            ->post(route('admin.approve', $property))
            ->assertNotFound();

        $this->assertSame(LifecycleState::Submitted, $property->fresh()->lifecycle_state);
    }

    public function test_a_moderator_sees_the_queue_and_the_review_screen(): void
    {
        $property  = $this->submittedListing();
        $moderator = $this->moderator();

        $this->actingAs($moderator)
            ->get(route('admin.queue'))
            ->assertOk()
            ->assertSee($property->title)
            ->assertSee('Awaiting review');

        $this->actingAs($moderator)
            ->get(route('admin.review', $property))
            ->assertOk()
            ->assertSee('Cost to move in')
            ->assertSee('Declared title')
            ->assertSee('Approve and publish');

        // FR-M12-01: opening a listing claims it, so two moderators do not
        // silently work the same one.
        $this->assertSame(LifecycleState::UnderReview, $property->fresh()->lifecycle_state);
    }

    public function test_approval_publishes_and_stamps_the_display_period(): void
    {
        $property = $this->submittedListing();

        $this->actingAs($this->moderator())
            ->post(route('admin.approve', $property))
            ->assertRedirect(route('admin.queue'));

        $property->refresh();

        $this->assertSame(LifecycleState::Published, $property->lifecycle_state);
        $this->assertNotNull($property->published_at);

        // FR-M2-08: the display period starts at approval, not submission, so a
        // listing that waited in the queue is not short-changed.
        $this->assertEqualsWithDelta(
            (int) config('agentpro.display_duration_days'),
            now()->diffInDays($property->expires_at),
            1
        );

        $this->get(route('property.show', $property))->assertOk();
    }

    public function test_rejection_requires_an_actionable_note(): void
    {
        $property = $this->submittedListing();

        $this->actingAs($this->moderator())
            ->post(route('admin.reject', $property), ['reason_code' => 'poor_media'])
            ->assertSessionHasErrors('note');

        $this->assertSame(LifecycleState::Submitted, $property->fresh()->lifecycle_state);
    }

    public function test_rejection_returns_the_listing_with_a_reason_the_lister_can_read(): void
    {
        $property = $this->submittedListing();

        $this->actingAs($this->moderator())->post(route('admin.reject', $property), [
            'reason_code' => 'incomplete_fees',
            'note' => 'The service charge is missing from the breakdown.',
        ])->assertRedirect(route('admin.queue'));

        $property->refresh();

        $this->assertSame(LifecycleState::Rejected, $property->lifecycle_state);
        $this->assertSame('incomplete_fees', $property->rejection_reason_code);
        $this->assertStringContainsString('service charge', $property->rejection_note);

        // Still not public.
        $this->get(route('property.show', $property))->assertNotFound();

        // And the lister can see why, on their own dashboard.
        $this->actingAs($property->lister)
            ->get(route('lister.dashboard'))
            ->assertOk()
            ->assertSee('service charge', false);
    }

    /** FR-M2-07 */
    public function test_a_live_listing_can_be_unpublished_with_a_reason(): void
    {
        $property = $this->submittedListing();
        $moderator = $this->moderator();

        app(ModerateListing::class)->approve($property, $moderator);
        $this->get(route('property.show', $property->fresh()))->assertOk();

        $this->actingAs($moderator)->post(route('admin.unlist', $property), [
            'outcome'     => 'other',
            'reason_code' => 'upheld_report',
        ])->assertRedirect(route('admin.queue'));

        $this->assertSame(LifecycleState::Unpublished, $property->fresh()->lifecycle_state);
        $this->get(route('property.show', $property->fresh()))->assertNotFound();
    }

    /** FR-M12-05: every visibility change is attributable to a person. */
    public function test_every_decision_is_attributed_on_the_audit_trail(): void
    {
        $property  = $this->submittedListing();
        $moderator = $this->moderator();

        $this->actingAs($moderator)->post(route('admin.approve', $property));

        $this->assertDatabaseHas('audit_events', [
            'action'     => 'listing.approved',
            'subject_id' => $property->id,
            'actor_id'   => $moderator->id,
        ]);
    }

    /** FR-M2-12: flagged for a human, never auto-blocked. */
    public function test_nearby_similar_listings_are_flagged_as_possible_duplicates(): void
    {
        $original = $this->submittedListing(null, 6.4441, 3.4795, '3-Bed Apartment, Ikate');
        app(ModerateListing::class)->approve($original, $this->moderator());

        // Same block, different agent, same shape and price.
        $copy = $this->submittedListing($this->user(), 6.44412, 3.47952, '3-Bed Apartment, Ikate');

        $candidates = app(\App\Queries\DuplicateCandidates::class)->for($copy);

        $this->assertCount(1, $candidates);
        $this->assertSame($original->id, $candidates[0]['property']->id);
        $this->assertStringContainsString(
            'Identical bedrooms and price',
            implode(' ', $candidates[0]['reasons'])
        );

        // Flagged, not blocked — the submission still stands.
        $this->assertSame(LifecycleState::Submitted, $copy->fresh()->lifecycle_state);
    }

    public function test_a_distant_listing_is_not_flagged(): void
    {
        $this->submittedListing(null, 6.4441, 3.4795);

        // Roughly 3km away.
        $other = $this->submittedListing($this->user(), 6.4700, 3.5000);

        $this->assertCount(0, app(\App\Queries\DuplicateCandidates::class)->for($other));
    }
}
