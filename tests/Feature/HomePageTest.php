<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The landing page.
 *
 * Everything on it is derived from what is actually in the database, and the
 * tests that matter are the ones holding that line. A marketplace landing page
 * is the easiest place in a product to start overstating: "thousands of
 * listings" when there are eight, a grid of areas that lead to empty results,
 * the same six properties under two headings to make the inventory look twice
 * the size. Each of those is a small lie that a visitor can check in one click,
 * on a platform whose entire proposition is that it does not do that.
 */
class HomePageTest extends TestCase
{
    use RefreshDatabase;

    private function lister(): User
    {
        return User::forceCreate([
            'uuid' => Str::uuid(), 'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'sellers_agent',
            'verification_state' => 'verified', 'verified_at' => now(),
        ]);
    }

    private function listing(string $slug = 'ikoyi', string $state = 'published', int $price = 7_500_000, bool $realsure = false): Property
    {
        $area = Area::firstOrCreate(['slug' => $slug], [
            'name' => Str::title(str_replace('-', ' ', $slug)),
            'city' => 'Lagos', 'state' => 'Lagos',
        ]);

        $property = Property::create([
            'uuid' => Str::uuid(), 'lister_id' => $this->lister()->id, 'area_id' => $area->id,
            'title' => Str::title($slug).' listing '.Str::random(4),
            'slug' => $slug.'-'.Str::lower(Str::random(6)),
            'listing_type' => 'apartment', 'intent' => 'rent', 'build_status' => 'fully_built',
            'address_line' => '12 Bourdillon Road', 'city' => 'Lagos', 'state' => 'Lagos',
            'lat' => 6.4488, 'lng' => 3.4390,
            'location' => DB::raw("ST_GeomFromText('POINT(3.4390 6.4488)')"),
            'lifecycle_state' => $state,
            'published_at' => $state === 'published' ? now()->subDay() : null,
            'realsure_verified_at' => $realsure ? now()->subDays(2) : null,
        ]);

        Unit::create([
            'uuid' => Str::uuid(), 'property_id' => $property->id, 'is_primary' => true,
            'price' => $price, 'price_period' => 'year', 'bedrooms' => 3, 'status' => 'available',
        ]);

        return $property;
    }

    public function test_the_figures_are_counted_rather_than_claimed(): void
    {
        $this->listing('ikoyi', 'published', 7_500_000, realsure: true);
        $this->listing('ikoyi', 'published', 9_000_000);
        // Not on the market, so it must not be counted anywhere.
        $this->listing('ikoyi', 'draft');

        $response = $this->get(route('home'))->assertOk();

        $response->assertSee('On the market')
            ->assertSee('RealSure verified')
            ->assertSee('Verified listers');

        // Two live, one verified — not three, and not "hundreds".
        $proof = $response->viewData('proof');

        $this->assertSame(2, $proof['live']);
        $this->assertSame(1, $proof['verified']);
        $this->assertSame(1, $proof['areas']);
    }

    /**
     * FR-M7-01 blocks publishing without a complete cost breakdown, so this
     * figure is 100% by construction. It is on the page because it sounds like
     * a boast and is actually a description of how publishing works.
     */
    public function test_the_fee_claim_matches_the_blocking_rule(): void
    {
        $this->listing();

        $this->get(route('home'))->assertOk()->assertSee('Full cost shown')->assertSee('100');
    }

    public function test_an_empty_platform_shows_no_figures_at_all(): void
    {
        // Better to show nothing than a row of zeroes, which reads as a broken
        // page rather than a new one.
        $this->get(route('home'))->assertOk()->assertDontSee('On the market');
    }

    /**
     * A grid of place names leading to empty result pages teaches a visitor
     * that the links do not work — the lesson risk R9 warns about for the map,
     * arriving through a different door.
     */
    public function test_only_areas_with_stock_are_offered(): void
    {
        $this->listing('lekki-phase-1');
        Area::firstOrCreate(['slug' => 'yaba'], ['name' => 'Yaba', 'city' => 'Lagos', 'state' => 'Lagos']);

        $response = $this->get(route('home'))->assertOk();

        $response->assertSee('Lekki Phase 1')->assertDontSee('Yaba');

        $this->assertCount(1, $response->viewData('places'));
    }

    /**
     * Two rows of the same listings under different headings would make the
     * inventory look twice the size it is.
     */
    public function test_just_listed_never_repeats_what_is_already_featured(): void
    {
        foreach (range(1, 8) as $i) {
            $this->listing('ikoyi');
        }

        $response = $this->get(route('home'))->assertOk();

        $featured = $response->viewData('featured')->pluck('id');
        $recent   = $response->viewData('recent')->pluck('id');

        $this->assertCount(6, $featured);
        $this->assertCount(2, $recent);
        $this->assertSame([], array_intersect($featured->all(), $recent->all()));
    }

    /**
     * The development media disk builds relative URLs.
     *
     * It used to be APP_URL.'/storage', which meant every photograph resolved
     * to port 80 while the application was served on 8000 or 8123 — nothing
     * displayed, and nobody noticed for as long as the seed produced only
     * placeholder artwork, which needs no file at all.
     */
    public function test_media_urls_do_not_depend_on_app_url(): void
    {
        config(['app.url' => 'http://some-other-host:9999']);

        $url = Storage::disk('public')->url('media/abc/def-800.webp');

        $this->assertSame('/storage/media/abc/def-800.webp', $url);
        $this->assertStringNotContainsString('some-other-host', $url);
    }
}
