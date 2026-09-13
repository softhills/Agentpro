<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Interaction;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The corporate and product pages (deliverable D2, FR-M1-07, FR-M5-08).
 *
 * Two things are being protected here. The first is that these pages exist at
 * all: every one of them was a dead `href="#"` in the header and footer, which
 * is the most visible thing that can be wrong with a site, and a link that
 * silently rots back to nothing would be worse than never having built them.
 *
 * The second is who appears on them. The agent directory and the sitemap both
 * publish people, and publishing an unverified lister would be lending
 * credibility nobody earned — the exact failure the platform exists to prevent.
 */
class CorporatePagesTest extends TestCase
{
    use RefreshDatabase;

    private function lister(array $attrs = []): User
    {
        return User::forceCreate(array_merge([
            'uuid' => Str::uuid(), 'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'sellers_agent',
            'verification_state' => 'verified', 'verified_at' => now()->subYear(),
            'created_at' => now()->subYear(),
        ], $attrs));
    }

    private function area(string $slug = 'ikoyi', bool $coverage = true): Area
    {
        return Area::firstOrCreate(['slug' => $slug], [
            'name' => Str::title(str_replace('-', ' ', $slug)),
            'city' => 'Lagos', 'state' => 'Lagos', 'is_scan_coverage' => $coverage,
        ]);
    }

    private function listing(User $lister, string $state = 'published', ?Area $area = null, int $price = 7500000): Property
    {
        $property = Property::create([
            'uuid' => Str::uuid(), 'lister_id' => $lister->id,
            'area_id' => ($area ?? $this->area())->id,
            'title' => '3-Bed Apartment, Ikoyi', 'slug' => 'ikoyi-'.Str::lower(Str::random(6)),
            'listing_type' => 'apartment', 'intent' => 'rent', 'build_status' => 'fully_built',
            'address_line' => '12 Bourdillon Road', 'city' => 'Lagos', 'state' => 'Lagos',
            'lat' => 6.4488, 'lng' => 3.4390,
            'location' => DB::raw("ST_GeomFromText('POINT(3.4390 6.4488)')"),
            'lifecycle_state' => $state,
            'published_at' => $state === 'published' ? now()->subDays(3) : null,
        ]);

        Unit::create([
            'uuid' => Str::uuid(), 'property_id' => $property->id, 'is_primary' => true,
            'price' => $price, 'price_period' => 'year', 'bedrooms' => 3,
        ]);

        return $property;
    }

    // ================================================================ they exist

    public function test_every_link_in_the_header_and_footer_goes_somewhere(): void
    {
        $this->listing($this->lister());

        foreach ([
            'pages.realsure', 'pages.areas', 'pages.agents',
            'pages.about', 'pages.terms', 'pages.privacy',
        ] as $route) {
            $this->get(route($route))->assertOk();
        }

        // And the chrome no longer points at nothing, which is the thing a
        // visitor notices before they look at a single listing.
        $this->get(route('home'))->assertOk()->assertDontSee('href="#"', false);
    }

    public function test_the_pages_need_no_account(): void
    {
        $this->listing($this->lister());

        // The whole marketing surface is the SEO surface. A page a crawler
        // cannot read is a page that does not exist (FR-M5-08).
        $this->get(route('pages.realsure'))->assertOk();
        $this->get(route('pages.agents'))->assertOk();
        $this->get(route('pages.privacy'))->assertOk();
        $this->assertGuest();
    }

    // ================================================================= realsure

    public function test_the_realsure_page_publishes_the_rule_for_the_badge(): void
    {
        $this->get(route('pages.realsure'))
            ->assertOk()
            ->assertSee('Title verification')
            ->assertSee('Drone photography')
            // A rule nobody can read is a rule nobody can hold us to.
            ->assertSee('A RealSure Officer grants it')
            ->assertSee('Photography and floor plans do not count')
            // And the disclaimer, stated before the sales pitch rather than after.
            ->assertSee('What the badge does not mean');
    }

    /**
     * The console used to own /realsure. The product name belongs to the page
     * the marketing site links to; an internal screen should never hold a URL
     * the public surface needs.
     */
    public function test_the_product_page_owns_the_realsure_url_and_the_console_moved(): void
    {
        $this->get('/realsure')->assertOk()->assertSee('A badge is only worth what it says it checked');

        $officer = $this->lister([
            'is_staff' => true, 'staff_role' => 'realsure_officer', 'category' => 'seeker',
        ]);

        $this->actingAs($officer)->get('/officer')->assertOk();
    }

    // ==================================================================== areas

    public function test_an_area_page_reports_live_stock_rather_than_prose(): void
    {
        $lister = $this->lister();
        $area = $this->area();

        $this->listing($lister, 'published', $area, 5000000);
        $this->listing($lister, 'published', $area, 9000000);
        $this->listing($lister, 'published', $area, 40000000);
        // Not on the market, so it must not be counted.
        $this->listing($lister, 'draft', $area, 1000);

        $response = $this->get(route('pages.area', $area))->assertOk();

        $response->assertSee('Live listings')
            // The median of 5m, 9m and 40m. A mean would be ₦18m, which
            // describes none of them.
            ->assertSee('₦9,000,000')
            ->assertSee('3D capture available here');
    }

    public function test_an_empty_area_says_so_instead_of_pretending(): void
    {
        $area = $this->area('yaba');

        $this->get(route('pages.area', $area))
            ->assertOk()
            ->assertSee('Nothing live here at the moment')
            ->assertSee('Save a search');
    }

    // =================================================================== agents

    public function test_only_verified_listers_with_live_stock_are_listed(): void
    {
        $verified = $this->lister(['name' => 'Tunde Adeyemi']);
        $this->listing($verified);

        $unverified = $this->lister(['name' => 'Unchecked Person', 'verification_state' => 'unverified']);
        $this->listing($unverified);

        $noStock = $this->lister(['name' => 'Dormant Agency']);

        $response = $this->get(route('pages.agents'))->assertOk();

        $response->assertSee('Tunde Adeyemi')
            // Listing an unverified account would lend credibility nobody
            // earned, which is the thing this platform exists to stop.
            ->assertDontSee('Unchecked Person')
            // And a directory of people with nothing to show wastes a visit.
            ->assertDontSee('Dormant Agency');
    }

    public function test_an_unverified_profile_404s_even_with_a_direct_link(): void
    {
        $unverified = $this->lister(['verification_state' => 'unverified']);
        $this->listing($unverified);

        // The directory only holds verified people; a direct URL must not be a
        // way around that.
        $this->get(route('pages.agent', $unverified))->assertNotFound();

        $seeker = $this->lister(['category' => 'seeker']);
        $this->get(route('pages.agent', $seeker))->assertNotFound();
    }

    public function test_a_profile_shows_the_six_facts_the_requirement_asks_for(): void
    {
        $lister = $this->lister();
        $this->listing($lister);
        $this->listing($lister);

        $this->get(route('pages.agent', $lister))
            ->assertOk()
            ->assertSee('Tunde Adeyemi')
            // Escaped, because the page renders the apostrophe as an entity.
            ->assertSee("Seller's agent")
            ->assertSee('Verified')
            ->assertSee('On the market')
            ->assertSee('on Agentpro since')
            ->assertSee('Rating');
    }

    /**
     * A "5.0" from one rating is not a reputation, and printing it as one would
     * mislead in the lister's favour — the opposite of what a trust platform is
     * for.
     */
    public function test_a_rating_is_withheld_until_there_are_enough_of_them(): void
    {
        $lister = $this->lister();
        $property = $this->listing($lister);

        $rater = $this->lister(['category' => 'seeker']);
        Interaction::create([
            'user_id' => $rater->id, 'property_id' => $property->id,
            'kind' => 'rate', 'rating' => 5,
        ]);

        $this->get(route('pages.agent', $lister))
            ->assertOk()
            ->assertDontSee('5.0')
            ->assertSee('1 so far');

        // Enough of them, and it appears.
        foreach (range(1, 2) as $i) {
            $other = $this->lister(['category' => 'seeker']);
            Interaction::create([
                'user_id' => $other->id, 'property_id' => $property->id,
                'kind' => 'rate', 'rating' => 4,
            ]);
        }

        $this->get(route('pages.agent', $lister))->assertOk()->assertSee('4.3');
    }

    // =================================================================== legal

    /**
     * The notice is generated from the same manifest the erasure runs on, so it
     * cannot describe something different from what the system actually does —
     * and adding a table forces it onto this page rather than leaving the page
     * quietly incomplete.
     */
    public function test_the_privacy_notice_is_generated_from_the_erasure_manifest(): void
    {
        $this->get(route('pages.privacy'))
            ->assertOk()
            ->assertSee('Deleted outright')
            ->assertSee('Kept as it is')
            ->assertSee('Saved searches')
            ->assertSee('Your statement')
            ->assertSee('six years')
            // The line the cookie banner rests on, stated in the notice too.
            ->assertSee('We do not attach an identifier', false)
            ->assertSee('Nigeria Data Protection Act 2023');
    }

    public function test_the_terms_state_what_agentpro_does_not_do(): void
    {
        $this->get(route('pages.terms'))
            ->assertOk()
            // The three that matter most in this market, and the ones a
            // fraudster would most like the page not to say.
            ->assertSee('No rent, deposit or purchase price passes through this platform', false)
            ->assertSee('makes no representation as to the legal validity of any title', false)
            ->assertSee('Instruct your own solicitor')
            // The listing policy, including the reasons a report can be made.
            ->assertSee('Itemise the cost of moving in');
    }

    // ================================================================= sitemap

    public function test_the_sitemap_lists_live_listings_and_not_the_rest(): void
    {
        Cache::flush();

        $lister = $this->lister();
        $live = $this->listing($lister, 'published');
        $draft = $this->listing($lister, 'draft');
        $rented = $this->listing($lister, 'rented');

        $response = $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $xml = $response->getContent();

        $this->assertStringContainsString(route('property.show', $live), $xml);
        $this->assertStringNotContainsString(route('property.show', $draft), $xml);
        /*
         * Rented listings stay reachable (FR-M2-09) so a followed link finds
         * something rather than a 404, but submitting them for indexing would
         * fill search results with property nobody can buy — the stale-listing
         * problem coming back through the front door.
         */
        $this->assertStringNotContainsString(route('property.show', $rented), $xml);

        // The corporate pages and the verified profile are in it.
        $this->assertStringContainsString(route('pages.realsure'), $xml);
        $this->assertStringContainsString(route('pages.agent', $lister), $xml);
    }

    public function test_the_sitemap_is_well_formed_xml(): void
    {
        Cache::flush();
        $this->listing($this->lister());

        $xml = $this->get('/sitemap.xml')->getContent();

        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertNotFalse($parsed, 'The sitemap did not parse as XML.');
        $this->assertSame([], $errors);
        $this->assertGreaterThan(5, $parsed->count());
    }

    public function test_an_unverified_lister_is_not_submitted_for_indexing(): void
    {
        Cache::flush();

        $unverified = $this->lister(['verification_state' => 'unverified']);
        $this->listing($unverified);

        // The directory will not show them, so the sitemap must not offer them
        // to a search engine either.
        $this->assertStringNotContainsString(
            route('pages.agent', $unverified),
            $this->get('/sitemap.xml')->getContent()
        );
    }
}
