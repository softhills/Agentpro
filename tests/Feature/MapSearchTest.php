<?php

namespace Tests\Feature;

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
 * Map-first search (FR-M5-02).
 *
 * The browser behaviour needs a real browser, but everything the map depends on
 * is server-side and testable here: whether a viewport returns clusters or pins,
 * whether the aggregation is actually done in SQL, and whether the result list
 * can be fetched as a fragment without the page chrome.
 */
class MapSearchTest extends TestCase
{
    use RefreshDatabase;

    /** Lagos Island / Lekki, the seeded corridor. */
    private const BOUNDS = ['south' => 6.30, 'west' => 3.30, 'north' => 6.60, 'east' => 3.60];

    private function lister(): User
    {
        return User::forceCreate([
            'uuid' => Str::uuid(), 'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'sellers_agent',
            'verification_state' => 'verified', 'verified_at' => now(),
        ]);
    }

    private function listing(float $lat, float $lng, float $price = 7_500_000, string $state = 'published'): Property
    {
        $area = Area::firstOrCreate(['slug' => 'lekki-phase-1'], [
            'name' => 'Lekki Phase 1', 'city' => 'Lagos', 'state' => 'Lagos',
            'is_scan_coverage' => true, 'centroid_lat' => 6.4478, 'centroid_lng' => 3.4723,
        ]);

        $property = Property::create([
            'uuid' => Str::uuid(), 'lister_id' => $this->lister()->id, 'area_id' => $area->id,
            'title' => '3-Bed Apartment', 'slug' => 'apt-'.Str::lower(Str::random(6)),
            'listing_type' => 'apartment', 'intent' => 'rent', 'build_status' => 'fully_built',
            'address_line' => 'Off Admiralty Way', 'city' => 'Lagos', 'state' => 'Lagos',
            'lat' => $lat, 'lng' => $lng,
            'location' => DB::raw(sprintf("ST_GeomFromText('POINT(%F %F)')", $lng, $lat)),
            'lifecycle_state' => $state,
            'published_at' => $state === 'published' ? now() : null,
        ]);

        Unit::create([
            'uuid' => Str::uuid(), 'property_id' => $property->id, 'is_primary' => true,
            'price' => $price, 'price_period' => 'year', 'bedrooms' => 3, 'status' => 'available',
        ]);

        return $property->fresh();
    }

    private function pins(int $zoom, array $extra = []): array
    {
        return $this->getJson(route('search.pins', array_merge(self::BOUNDS, ['zoom' => $zoom], $extra)))
            ->assertOk()
            ->json();
    }

    /**
     * NFR-02: a city-wide viewport must not ship a row per listing.
     *
     * Twelve listings within about a kilometre of each other come back as a
     * handful of aggregated markers, not twelve.
     */
    public function test_a_zoomed_out_viewport_returns_aggregated_clusters(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->listing(6.4440 + ($i * 0.0008), 3.4790 + ($i * 0.0008));
        }

        $response = $this->pins(zoom: 10);

        $this->assertSame('clusters', $response['mode']);
        $this->assertLessThan(12, count($response['markers']), 'clusters should aggregate');

        // Every listing is still accounted for, just grouped.
        $this->assertSame(12, array_sum(array_column($response['markers'], 'count')));

        // Cluster markers carry no listing identity — that is the point.
        $this->assertArrayNotHasKey('id', $response['markers'][0]);
    }

    public function test_a_zoomed_in_viewport_returns_individual_pins(): void
    {
        $this->listing(6.4441, 3.4795, 7_500_000);
        $this->listing(6.4460, 3.4810, 9_000_000);

        $response = $this->pins(zoom: 16);

        $this->assertSame('pins', $response['mode']);
        $this->assertCount(2, $response['markers']);
        $this->assertArrayHasKey('id', $response['markers'][0]);
        $this->assertArrayHasKey('price', $response['markers'][0]);
        $this->assertArrayHasKey('realsure', $response['markers'][0]);
    }

    /** The grid tightens as the seeker zooms, so clusters do not collapse into one. */
    public function test_clusters_get_finer_as_zoom_increases(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->listing(6.4400 + ($i * 0.004), 3.4700 + ($i * 0.004));
        }

        $wide  = count($this->pins(zoom: 9)['markers']);
        $tight = count($this->pins(zoom: 13)['markers']);

        $this->assertGreaterThanOrEqual($wide, $tight, 'zooming in should not merge markers');
    }

    /** The viewport is a filter, not decoration. */
    public function test_markers_outside_the_viewport_are_excluded(): void
    {
        $inside  = $this->listing(6.4441, 3.4795);
        $outside = $this->listing(9.0765, 7.4620);   // Abuja

        $ids = array_column($this->pins(zoom: 16)['markers'], 'id');

        $this->assertContains($inside->uuid, $ids);
        $this->assertNotContains($outside->uuid, $ids);
    }

    /** Visibility is decided by the same query, so drafts cannot leak onto the map. */
    public function test_unpublished_listings_never_appear_as_markers(): void
    {
        $draft = $this->listing(6.4441, 3.4795, state: 'draft');
        $this->listing(6.4450, 3.4800);

        $response = $this->pins(zoom: 16);

        $this->assertNotContains($draft->uuid, array_column($response['markers'], 'id'));
        $this->assertCount(1, $response['markers']);
    }

    /** Filters apply to the map as well as the list, or the two disagree. */
    public function test_filters_apply_to_map_markers(): void
    {
        $cheap = $this->listing(6.4441, 3.4795, 6_000_000);
        $dear  = $this->listing(6.4450, 3.4800, 40_000_000);

        $ids = array_column($this->pins(zoom: 16, extra: ['max_price' => 10_000_000])['markers'], 'id');

        $this->assertContains($cheap->uuid, $ids);
        $this->assertNotContains($dear->uuid, $ids);
    }

    /**
     * Panning must not re-render the whole page (NFR-02): the list comes back
     * as a fragment the map swaps in.
     */
    public function test_the_results_fragment_returns_the_list_without_page_chrome(): void
    {
        $this->listing(6.4441, 3.4795);

        $full = $this->get(route('search', self::BOUNDS))->assertOk()->getContent();
        $fragment = $this->get(route('search', self::BOUNDS + ['fragment' => 1]))->assertOk()->getContent();

        $this->assertStringContainsString('<html', $full);
        $this->assertStringNotContainsString('<html', $fragment, 'the fragment must not carry page chrome');
        $this->assertStringNotContainsString('filterbar', $fragment, 'the filter bar is not part of the list');
        $this->assertStringContainsString('class="card"', $fragment);

        // The fragment is meaningfully smaller — that saving is the whole point.
        $this->assertLessThan(strlen($full), strlen($fragment));
    }

    /** Risk R9: the map must not open on an empty national view. */
    public function test_the_map_opens_on_the_seeded_corridor_by_default(): void
    {
        $this->listing(6.4441, 3.4795);

        $html = $this->get(route('search'))->assertOk()->getContent();

        preg_match('/data-config="([^"]*)"/', $html, $matches);
        $config = json_decode(html_entity_decode($matches[1] ?? '{}'), true);

        $this->assertEqualsWithDelta(6.445, $config['center']['lat'], 0.2);
        $this->assertEqualsWithDelta(3.455, $config['center']['lng'], 0.2);
        $this->assertGreaterThanOrEqual(10, $config['zoom']);
    }

    /** Filtering to an area should centre there rather than on the default. */
    public function test_filtering_by_area_centres_the_map_on_it(): void
    {
        Area::firstOrCreate(['slug' => 'maitama'], [
            'name' => 'Maitama', 'city' => 'Abuja', 'state' => 'FCT',
            'is_scan_coverage' => true, 'centroid_lat' => 9.0854, 'centroid_lng' => 7.4915,
        ]);

        $html = $this->get(route('search', ['area' => 'maitama']))->assertOk()->getContent();

        preg_match('/data-config="([^"]*)"/', $html, $matches);
        $config = json_decode(html_entity_decode($matches[1] ?? '{}'), true);

        $this->assertEqualsWithDelta(9.0854, $config['center']['lat'], 0.01);
        $this->assertEqualsWithDelta(7.4915, $config['center']['lng'], 0.01);
    }

    /** SEC-10: the pin endpoint is the cheapest way to scrape inventory. */
    public function test_the_pin_endpoint_is_rate_limited(): void
    {
        $this->assertContains(
            'throttle:60,1',
            app('router')->getRoutes()->getByName('search.pins')->gatherMiddleware()
        );
    }
}
