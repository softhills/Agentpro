<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Interaction;
use App\Models\PriceHistory;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The listing detail page, against the HotPads benchmark in PRD §16.
 *
 * Four R1 parity items lived only on the server before this: saving, hiding and
 * reporting a listing all had routes, actions and passing tests, while the page
 * a seeker would use them on had three buttons that did nothing at all. Price
 * history was eager-loaded and never rendered. The freshness line was on the
 * card and missing from the page where somebody decides whether a listing is
 * still real.
 *
 * So most of what follows checks that the page actually reaches the behaviour
 * underneath it — the failure mode where every unit test passes and the feature
 * does not exist.
 */
class ListingDetailTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Linux; Android 12) Chrome/120.0 Mobile Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        // Otherwise every request here is treated as a crawler and the demand
        // figures below would be zero for the wrong reason.
        $this->withHeader('User-Agent', self::BROWSER);
    }

    private function seeker(): User
    {
        return User::forceCreate([
            'uuid' => Str::uuid(), 'name' => 'Amaka Obi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'seeker',
        ]);
    }

    private function listing(string $state = 'published'): Property
    {
        $lister = User::forceCreate([
            'uuid' => Str::uuid(), 'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'sellers_agent',
            'phone' => '+2348030000001',
            'verification_state' => 'verified',
        ]);

        $area = Area::firstOrCreate(['slug' => 'ikoyi'], [
            'name' => 'Ikoyi', 'city' => 'Lagos', 'state' => 'Lagos',
        ]);

        $property = Property::create([
            'uuid' => Str::uuid(), 'lister_id' => $lister->id, 'area_id' => $area->id,
            'title' => '3-Bed Apartment, Ikoyi', 'slug' => 'ikoyi-'.Str::lower(Str::random(6)),
            'listing_type' => 'apartment', 'intent' => 'rent', 'build_status' => 'fully_built',
            'address_line' => '12 Bourdillon Road', 'city' => 'Lagos', 'state' => 'Lagos',
            'lat' => 6.4488, 'lng' => 3.4390, 'what3words' => '///plant.chief.maker',
            'location' => DB::raw("ST_GeomFromText('POINT(3.4390 6.4488)')"),
            'lifecycle_state' => $state,
            'published_at' => $state === 'published' ? now()->subDays(2) : null,
            'content_updated_at' => $state === 'published' ? now()->subHours(3) : null,
        ]);

        Unit::create([
            'uuid' => Str::uuid(), 'property_id' => $property->id, 'is_primary' => true,
            'price' => 7500000, 'price_period' => 'year', 'bedrooms' => 3,
        ]);

        return $property;
    }

    // ============================================= the buttons that did nothing

    public function test_saving_from_the_listing_page_actually_saves_it(): void
    {
        $property = $this->listing();
        $seeker = $this->seeker();

        $this->actingAs($seeker)->get(route('property.show', $property))
            ->assertOk()
            ->assertSee(route('interact.save', $property), false);

        $this->actingAs($seeker)->post(route('interact.save', $property))->assertRedirect();

        $this->assertDatabaseHas('interactions', [
            'user_id' => $seeker->id, 'property_id' => $property->id, 'kind' => 'save',
        ]);

        // And the page reflects it rather than offering to save it again.
        $this->actingAs($seeker)->get(route('property.show', $property))
            ->assertOk()->assertSee('Saved');
    }

    public function test_hiding_is_reachable_from_the_listing_page(): void
    {
        $property = $this->listing();
        $seeker = $this->seeker();

        $this->actingAs($seeker)->get(route('property.show', $property))
            ->assertOk()
            ->assertSee(route('interact.hide', $property), false);

        $this->actingAs($seeker)->post(route('interact.hide', $property));

        $this->assertDatabaseHas('interactions', [
            'user_id' => $seeker->id, 'property_id' => $property->id, 'kind' => 'hide',
        ]);

        $this->actingAs($seeker)->get(route('property.show', $property))
            ->assertOk()->assertSee('Unhide');
    }

    /**
     * HotPads ends every listing with "Spot something off? Flag it and help keep
     * listings legit". Somebody who has just read the whole page is exactly who
     * notices something wrong, which is why the route exists at the bottom as
     * well as a control at the top.
     */
    public function test_a_seeker_can_report_a_listing_from_the_page(): void
    {
        $property = $this->listing();
        $seeker = $this->seeker();

        $this->actingAs($seeker)->get(route('property.show', $property))
            ->assertOk()
            ->assertSee('Spot something wrong?')
            // The reason taxonomy, rendered rather than described (FR-M6-07).
            ->assertSee('Listing appears fraudulent')
            ->assertSee('Duplicate of another listing');

        $this->actingAs($seeker)->post(route('interact.report', $property), [
            'reason_code' => 'fraudulent',
            'note' => 'The photos are of a different building.',
        ])->assertRedirect();

        $this->assertDatabaseHas('interactions', [
            'property_id' => $property->id, 'kind' => 'report', 'reason_code' => 'fraudulent',
        ]);

        // Reported once, and the form is replaced rather than offered again.
        $this->actingAs($seeker)->get(route('property.show', $property))
            ->assertOk()->assertSee('You have reported this listing');
    }

    public function test_a_guest_is_sent_to_sign_in_rather_than_given_a_dead_button(): void
    {
        $property = $this->listing();

        $response = $this->get(route('property.show', $property))->assertOk();

        // FR-M1-03: an account is the price of acting on a listing, not of
        // seeing one — so the controls point at sign-in, not at nothing.
        $response->assertSee(route('login'), false)
            ->assertSee('to report it', false);

        $this->assertSame(0, Interaction::count());
    }

    // ================================================================== contact

    /**
     * All four contact controls were <button type="button"> wired to nothing —
     * Call and WhatsApp as dead as the two that got reported.
     */
    public function test_every_contact_control_reaches_the_lister(): void
    {
        $property = $this->listing();
        $seeker = $this->seeker();

        $expected = [
            ['phone', [], 'tel:+2348030000001'],
            ['whatsapp', [], 'https://wa.me/2348030000001'],
            ['email', [], 'mailto:'],
            // "Request a viewing" is an email with a different subject.
            ['email', ['intent' => 'viewing'], 'mailto:'],
        ];

        foreach ($expected as [$mode, $extra, $needle]) {
            $this->actingAs($seeker)
                ->post(route('interact.contact', $property), ['mode' => $mode] + $extra)
                ->assertRedirect();

            $this->actingAs($seeker)->get(route('property.show', $property))
                ->assertOk()
                ->assertSee($needle, false);
        }
    }

    public function test_a_viewing_request_says_what_it_is_for(): void
    {
        $property = $this->listing();

        $this->actingAs($this->seeker())
            ->post(route('interact.contact', $property), ['mode' => 'email', 'intent' => 'viewing']);

        $mailto = urldecode(
            $this->actingAs($this->seeker())->get(route('property.show', $property))->getContent()
        );

        $this->assertStringContainsString('Viewing request', $mailto);
        $this->assertStringContainsString('arrange a viewing', $mailto);
    }

    /**
     * The reason the details are handed back by the server rather than rendered
     * into the page: a phone number in the HTML is available to anybody who
     * views source, account or not, and contact details are the most scrapeable
     * thing a marketplace holds (SEC-10, FR-M1-03).
     */
    public function test_a_guest_is_offered_sign_in_and_never_the_number(): void
    {
        $property = $this->listing();

        $html = $this->get(route('property.show', $property))->assertOk()->getContent();

        $this->assertStringContainsString('Sign in to contact', $html);
        $this->assertStringNotContainsString('2348030000001', $html);
        $this->assertStringNotContainsString('tel:', $html);
        $this->assertStringNotContainsString('wa.me', $html);

        // And the endpoint itself is closed to them.
        $this->post(route('interact.contact', $property), ['mode' => 'phone'])
            ->assertRedirect(route('login'));
    }

    public function test_the_number_is_not_in_the_page_until_it_is_asked_for(): void
    {
        $property = $this->listing();
        $seeker = $this->seeker();

        // Signed in, but has not pressed anything yet.
        $html = $this->actingAs($seeker)->get(route('property.show', $property))->getContent();

        $this->assertStringNotContainsString('2348030000001', $html);
        $this->assertStringContainsString('Email the agent', $html);
    }

    public function test_each_initiation_is_counted_by_mode(): void
    {
        $property = $this->listing();
        $seeker = $this->seeker();

        foreach (['phone', 'whatsapp', 'email'] as $mode) {
            $this->actingAs($seeker)->post(route('interact.contact', $property), ['mode' => $mode]);
        }

        // One standing relationship — the interaction is unique per person and
        // listing — but three initiations, which is the distinction FR-M13-01
        // needs and `interactions` cannot express.
        $this->assertSame(1, Interaction::where('kind', 'contact')->count());

        $modes = DB::table('analytics_events')->where('name', 'contact')
            ->pluck('context')->sort()->values()->all();

        $this->assertSame(['email', 'phone', 'whatsapp'], $modes);
    }

    /**
     * A lister with no number on file is a real state — phone is nullable and
     * registration by email does not collect one. Better to say so than to hand
     * back a tel: link to nothing.
     */
    public function test_a_lister_with_no_number_says_so_rather_than_failing(): void
    {
        $property = $this->listing();
        $property->lister->forceFill(['phone' => null])->save();

        $this->actingAs($this->seeker())
            ->post(route('interact.contact', $property), ['mode' => 'phone'])
            ->assertRedirect();

        $this->actingAs($this->seeker())->get(route('property.show', $property))
            ->assertOk()
            ->assertSee('has not given us a number')
            ->assertDontSee('tel:', false);
    }

    public function test_an_invented_contact_mode_is_refused(): void
    {
        $property = $this->listing();

        $this->actingAs($this->seeker())
            ->post(route('interact.contact', $property), ['mode' => 'carrier_pigeon'])
            ->assertSessionHasErrors('mode');
    }

    // ================================================================ freshness

    public function test_the_page_says_when_it_was_last_updated(): void
    {
        $property = $this->listing();

        // The line HotPads puts directly above the price (FR-M2-14).
        $this->get(route('property.show', $property))
            ->assertOk()
            ->assertSee('Updated')
            ->assertSee('3 hours ago')
            ->assertSee('New');
    }

    // ============================================================ price history

    public function test_price_history_is_shown_once_the_price_has_moved(): void
    {
        $property = $this->listing();
        $unit = $property->units()->first();

        PriceHistory::create([
            'unit_id' => $unit->id, 'price' => 9000000,
            'price_period' => 'year', 'effective_at' => now()->subMonths(2),
        ]);
        PriceHistory::create([
            'unit_id' => $unit->id, 'price' => 7500000,
            'price_period' => 'year', 'effective_at' => now()->subDays(6),
        ]);

        $this->get(route('property.show', $property))
            ->assertOk()
            ->assertSee('Price history')
            ->assertSee('₦9,000,000')
            // A drop of ₦1.5m, which is the thing worth seeing: either the
            // lister is finding the market or the listing has been sitting.
            ->assertSee('₦1,500,000');
    }

    public function test_a_listing_whose_price_never_moved_shows_no_history(): void
    {
        $property = $this->listing();

        // One point is not a history, and a panel containing a single row
        // implies a change that did not happen.
        PriceHistory::create([
            'unit_id' => $property->units()->first()->id, 'price' => 7500000,
            'price_period' => 'year', 'effective_at' => now()->subMonth(),
        ]);

        $this->get(route('property.show', $property))->assertOk()->assertDontSee('Price history');
    }

    // =================================================================== demand

    /**
     * HotPads calls this "Competition for this rental". It is the same figure
     * the lister sees on their own performance screen, so the two cannot tell
     * different stories about one listing.
     */
    public function test_interest_is_shown_once_there_is_enough_of_it(): void
    {
        $property = $this->listing();

        foreach (range(1, 7) as $i) {
            DB::table('analytics_events')->insert([
                'name' => 'detail', 'property_id' => $property->id, 'occurred_at' => now()->subDay(),
            ]);
        }
        DB::table('analytics_events')->insert([
            'name' => 'contact', 'property_id' => $property->id,
            'context' => 'whatsapp', 'occurred_at' => now()->subDay(),
        ]);

        $this->get(route('property.show', $property))
            ->assertOk()
            ->assertSee('Interest this week')
            ->assertSee('7')
            ->assertSee('contacted');
    }

    /**
     * "Viewed 2 times this week" reads as a dead listing whether or not it is
     * one, and at launch — when supply is thin because every listing is
     * human-approved — publishing that about somebody's property helps nobody.
     */
    public function test_a_thin_week_is_not_published_as_a_discouraging_number(): void
    {
        $property = $this->listing();

        DB::table('analytics_events')->insert([
            'name' => 'detail', 'property_id' => $property->id, 'occurred_at' => now()->subDay(),
        ]);

        $this->get(route('property.show', $property))->assertOk()->assertDontSee('Interest this week');
    }

    public function test_stale_interest_does_not_count(): void
    {
        $property = $this->listing();

        foreach (range(1, 9) as $i) {
            DB::table('analytics_events')->insert([
                'name' => 'detail', 'property_id' => $property->id,
                // Outside the seven-day window the heading promises.
                'occurred_at' => now()->subDays(20),
            ]);
        }

        $this->get(route('property.show', $property))->assertOk()->assertDontSee('Interest this week');
    }

    // ====================================================================== map

    public function test_the_listing_carries_a_map_of_where_it_is(): void
    {
        $property = $this->listing();

        $response = $this->get(route('property.show', $property))->assertOk();

        $response->assertSee('Where it is')
            ->assertSee('detail-map', false)
            ->assertSee('js/detail-map.js', false)
            // The same Leaflet build as the search map rather than a second
            // map stack on this page.
            ->assertSee('vendor/leaflet/leaflet.js', false)
            ->assertSee('///plant.chief.maker');

        // The coordinate reaches the client, which is the part that would fail
        // silently if the config shape ever drifted.
        $this->assertStringContainsString('6.4488', $response->getContent());
    }

    public function test_none_of_this_leaks_onto_a_listing_that_is_not_public(): void
    {
        $property = $this->listing('draft');

        $this->get(route('property.show', $property))->assertNotFound();
    }
}
