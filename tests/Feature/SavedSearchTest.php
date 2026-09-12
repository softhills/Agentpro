<?php

namespace Tests\Feature;

use App\Actions\MatchSavedSearch;
use App\Enums\LifecycleState;
use App\Models\Area;
use App\Models\Property;
use App\Models\SavedSearch;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\SavedSearchMatches;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Saved-search matching (FR-M5-07).
 *
 * Promoted to R1 because it is the loop that brings a seeker back before they
 * have found anything. The tests concentrate on the two ways it can be quietly
 * useless: alerting about things the seeker has already seen, and failing to
 * alert about the thing they most wanted.
 */
class SavedSearchTest extends TestCase
{
    use RefreshDatabase;

    private function seeker(): User
    {
        return User::forceCreate([
            'uuid' => Str::uuid(), 'name' => 'Amaka Obi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'seeker',
            'verification_state' => 'unverified',
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

    private function area(): Area
    {
        return Area::firstOrCreate(['slug' => 'lekki-phase-1'], [
            'name' => 'Lekki Phase 1', 'city' => 'Lagos', 'state' => 'Lagos',
            'is_scan_coverage' => true,
        ]);
    }

    private function listing(float $price, ?User $lister = null, int $beds = 3, string $state = 'published'): Property
    {
        $lister ??= $this->lister();

        $property = Property::create([
            'uuid' => Str::uuid(), 'lister_id' => $lister->id, 'area_id' => $this->area()->id,
            'title' => $beds.'-Bed Apartment, Lekki', 'slug' => 'lekki-'.Str::lower(Str::random(6)),
            'listing_type' => 'apartment', 'intent' => 'rent', 'build_status' => 'fully_built',
            'address_line' => 'Off Admiralty Way', 'city' => 'Lagos', 'state' => 'Lagos',
            'lat' => 6.4478, 'lng' => 3.4723,
            'location' => DB::raw("ST_GeomFromText('POINT(3.4723 6.4478)')"),
            'lifecycle_state' => $state,
            'published_at' => $state === 'published' ? now() : null,
        ]);

        Unit::create([
            'uuid' => Str::uuid(), 'property_id' => $property->id, 'is_primary' => true,
            'price' => $price, 'price_period' => 'year',
            'bedrooms' => $beds, 'bathrooms' => $beds, 'status' => 'available',
        ]);

        return $property->fresh();
    }

    private function savedSearch(User $user, array $criteria, string $frequency = 'instant'): SavedSearch
    {
        return SavedSearch::create([
            'user_id' => $user->id,
            'name' => 'My search',
            'criteria' => $criteria,
            'frequency' => $frequency,
        ]);
    }

    // ------------------------------------------------------------- the baseline

    /**
     * A saved search is a request about the future.
     *
     * Without a baseline the first run reports the entire matching inventory —
     * the results the seeker has just finished scrolling past.
     */
    public function test_listings_that_already_matched_are_not_reported_as_new(): void
    {
        Notification::fake();

        $seeker = $this->seeker();
        $this->listing(7_000_000);
        $this->listing(8_000_000);

        $search = $this->savedSearch($seeker, ['max_price' => 12_000_000]);

        $seeded = app(MatchSavedSearch::class)->seedBaseline($search);
        $this->assertSame(2, $seeded);

        $this->artisan('agentpro:run-saved-searches --frequency=instant')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_a_listing_published_after_the_search_is_reported(): void
    {
        Notification::fake();

        $seeker = $this->seeker();
        $search = $this->savedSearch($seeker, ['max_price' => 12_000_000]);
        app(MatchSavedSearch::class)->seedBaseline($search);

        $new = $this->listing(9_500_000);

        $this->artisan('agentpro:run-saved-searches --frequency=instant')->assertSuccessful();

        Notification::assertSentTo(
            $seeker,
            SavedSearchMatches::class,
            fn (SavedSearchMatches $n) => $n->properties->contains('id', $new->id)
        );
    }

    /**
     * The case a timestamp watermark gets wrong, and the most valuable alert
     * this feature can send.
     *
     * A listing published before the search was saved, at a price above the
     * ceiling, drops into range. Its published_at never moves, so "anything
     * published since the last run" would never find it.
     */
    public function test_a_price_drop_into_range_is_reported(): void
    {
        Notification::fake();

        $seeker = $this->seeker();
        $expensive = $this->listing(15_000_000);

        $search = $this->savedSearch($seeker, ['max_price' => 12_000_000]);
        app(MatchSavedSearch::class)->seedBaseline($search);

        // Nothing matched at the time of saving.
        $this->assertSame(0, $search->matches()->count());

        $expensive->units->first()->update(['price' => 11_000_000]);

        $this->artisan('agentpro:run-saved-searches --frequency=instant')->assertSuccessful();

        Notification::assertSentTo(
            $seeker,
            SavedSearchMatches::class,
            fn (SavedSearchMatches $n) => $n->properties->contains('id', $expensive->id)
        );
    }

    // -------------------------------------------------------------- duplication

    public function test_a_listing_is_reported_only_once(): void
    {
        Notification::fake();

        $seeker = $this->seeker();
        $search = $this->savedSearch($seeker, ['max_price' => 12_000_000]);
        app(MatchSavedSearch::class)->seedBaseline($search);

        $this->listing(9_000_000);

        $this->artisan('agentpro:run-saved-searches --frequency=instant');
        $this->artisan('agentpro:run-saved-searches --frequency=instant');
        $this->artisan('agentpro:run-saved-searches --frequency=instant');

        Notification::assertSentToTimes($seeker, SavedSearchMatches::class, 1);
    }

    /** Unpublishing and republishing must not produce a second alert. */
    public function test_republishing_does_not_re_report(): void
    {
        Notification::fake();

        $seeker = $this->seeker();
        $search = $this->savedSearch($seeker, ['max_price' => 12_000_000]);
        app(MatchSavedSearch::class)->seedBaseline($search);

        $listing = $this->listing(9_000_000);
        $this->artisan('agentpro:run-saved-searches --frequency=instant');

        $listing->update(['lifecycle_state' => LifecycleState::Unpublished->value]);
        $listing->update([
            'lifecycle_state' => LifecycleState::Published->value,
            'published_at' => now(),
        ]);

        $this->artisan('agentpro:run-saved-searches --frequency=instant');

        Notification::assertSentToTimes($seeker, SavedSearchMatches::class, 1);
    }

    // ----------------------------------------------------------------- matching

    /** The criteria mean the same thing here as they do on the search page. */
    public function test_criteria_are_honoured(): void
    {
        Notification::fake();

        $seeker = $this->seeker();
        $search = $this->savedSearch($seeker, ['max_price' => 10_000_000, 'beds' => 3]);
        app(MatchSavedSearch::class)->seedBaseline($search);

        $tooExpensive = $this->listing(14_000_000, beds: 3);
        $tooSmall     = $this->listing(8_000_000, beds: 1);
        $justRight    = $this->listing(9_000_000, beds: 3);

        $matches = app(MatchSavedSearch::class)->unreported($search);

        $this->assertTrue($matches->contains('id', $justRight->id));
        $this->assertFalse($matches->contains('id', $tooExpensive->id));
        $this->assertFalse($matches->contains('id', $tooSmall->id));
    }

    /** Visibility is decided by the same query, so drafts can never leak. */
    public function test_unpublished_listings_are_never_matched(): void
    {
        $seeker = $this->seeker();
        $search = $this->savedSearch($seeker, ['max_price' => 12_000_000]);
        app(MatchSavedSearch::class)->seedBaseline($search);

        $draft = $this->listing(9_000_000, state: 'draft');

        $this->assertFalse(
            app(MatchSavedSearch::class)->unreported($search)->contains('id', $draft->id)
        );
    }

    public function test_a_lister_is_not_alerted_about_their_own_listing(): void
    {
        $lister = $this->lister();
        $search = $this->savedSearch($lister, ['max_price' => 12_000_000]);
        app(MatchSavedSearch::class)->seedBaseline($search);

        $own = $this->listing(9_000_000, $lister);

        $this->assertFalse(
            app(MatchSavedSearch::class)->unreported($search)->contains('id', $own->id)
        );
    }

    public function test_a_paused_search_is_not_run(): void
    {
        Notification::fake();

        $seeker = $this->seeker();
        $search = $this->savedSearch($seeker, ['max_price' => 12_000_000], frequency: 'off');
        app(MatchSavedSearch::class)->seedBaseline($search);

        $this->listing(9_000_000);

        $this->artisan('agentpro:run-saved-searches --frequency=instant');
        $this->artisan('agentpro:run-saved-searches --frequency=daily');

        Notification::assertNothingSent();
    }

    public function test_daily_and_instant_searches_run_on_their_own_cadence(): void
    {
        Notification::fake();

        $instantSeeker = $this->seeker();
        $dailySeeker   = $this->seeker();

        $instant = $this->savedSearch($instantSeeker, ['max_price' => 12_000_000], 'instant');
        $daily   = $this->savedSearch($dailySeeker, ['max_price' => 12_000_000], 'daily');

        app(MatchSavedSearch::class)->seedBaseline($instant);
        app(MatchSavedSearch::class)->seedBaseline($daily);

        $this->listing(9_000_000);

        $this->artisan('agentpro:run-saved-searches --frequency=instant');

        Notification::assertSentTo($instantSeeker, SavedSearchMatches::class);
        Notification::assertNotSentTo($dailySeeker, SavedSearchMatches::class);
    }

    /** One message for the batch, not one per listing. */
    public function test_several_matches_arrive_as_one_message(): void
    {
        Notification::fake();

        $seeker = $this->seeker();
        $search = $this->savedSearch($seeker, ['max_price' => 12_000_000]);
        app(MatchSavedSearch::class)->seedBaseline($search);

        $this->listing(7_000_000);
        $this->listing(8_000_000);
        $this->listing(9_000_000);

        $this->artisan('agentpro:run-saved-searches --frequency=instant');

        Notification::assertSentToTimes($seeker, SavedSearchMatches::class, 1);
        Notification::assertSentTo(
            $seeker,
            SavedSearchMatches::class,
            fn (SavedSearchMatches $n) => $n->properties->count() === 3
        );
    }

    // ---------------------------------------------------------------- the screen

    public function test_saving_a_search_from_the_results_page_seeds_its_baseline(): void
    {
        $seeker = $this->seeker();
        $this->listing(7_000_000);

        $this->actingAs($seeker)
            ->post(route('saved-searches.store'), ['max_price' => 12_000_000, 'beds' => 3])
            ->assertRedirect();

        $search = SavedSearch::firstOrFail();

        $this->assertSame($seeker->id, $search->user_id);
        $this->assertSame(12_000_000, (int) $search->criteria['max_price']);
        $this->assertSame(1, $search->matches()->count(), 'the existing match is baselined, not alerted');
    }

    public function test_a_seeker_cannot_touch_another_persons_saved_search(): void
    {
        $search = $this->savedSearch($this->seeker(), ['max_price' => 12_000_000]);

        $this->actingAs($this->seeker())
            ->delete(route('saved-searches.destroy', $search))
            ->assertNotFound();

        $this->assertDatabaseHas('saved_searches', ['id' => $search->id]);
    }

    public function test_the_saved_search_list_renders(): void
    {
        $seeker = $this->seeker();
        $this->savedSearch($seeker, ['max_price' => 12_000_000, 'beds' => 3, 'q' => 'Lekki']);

        $this->actingAs($seeker)
            ->get(route('saved-searches.index'))
            ->assertOk()
            // describe() is generated from the criteria, so it cannot go stale.
            ->assertSee('3+ bed')
            ->assertSee('in Lekki');
    }
}
