<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Interaction;
use App\Models\Order;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Queries\Funnel;
use App\Queries\ListingAnalytics;
use App\Support\Analytics;
use App\Support\Consent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Analytics (M13).
 *
 * The tests that matter here are not "does it count" — they are the ones about
 * what it must refuse to count. A view counter is only worth having if a lister
 * believes it, and everything that makes it believable is a subtraction: bots
 * excluded, reloads collapsed, drafts uncounted, map panning not mistaken for
 * searching.
 *
 * The other half is consent. FR-M13-04 says no non-essential tracking before
 * consent, and the line this implementation draws is between counting that an
 * event happened and following a person between requests. Several tests below
 * exist to hold that line in both directions: totals must survive a refusal,
 * and the visitor id must not.
 */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    /** A plausible mobile browser. Most of these tests turn on it. */
    private const BROWSER = 'Mozilla/5.0 (Linux; Android 12; Infinix X6819) AppleWebKit/537.36 '
        .'(KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        // Without this every request in this file looks like a bot to
        // Consent::looksLikeABot(), and every assertion below would pass for
        // the wrong reason.
        $this->withHeader('User-Agent', self::BROWSER);
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

    private function seeker(): User
    {
        return User::forceCreate([
            'uuid' => Str::uuid(), 'name' => 'Amaka Obi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'seeker',
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
            'listing_type' => 'apartment', 'intent' => 'rent', 'build_status' => 'fully_built',
            'address_line' => '12 Bourdillon Road', 'city' => 'Lagos', 'state' => 'Lagos',
            'lat' => 6.4488, 'lng' => 3.4390,
            'location' => DB::raw("ST_GeomFromText('POINT(3.4390 6.4488)')"),
            'lifecycle_state' => $state,
            'published_at' => $state === 'published' ? now() : null,
        ]);

        Unit::create([
            'uuid' => Str::uuid(), 'property_id' => $property->id, 'is_primary' => true,
            'price' => 7500000, 'price_period' => 'year', 'bedrooms' => 3,
        ]);

        return $property;
    }

    // ================================================================== counting

    public function test_opening_a_listing_is_counted_once_per_visit(): void
    {
        $property = $this->listing();

        // Three requests in one session — a seeker reading, going back to the
        // results and returning, which is ordinary behaviour and one view.
        $this->get(route('property.show', $property))->assertOk();
        $this->get(route('property.show', $property))->assertOk();
        $this->get(route('property.show', $property))->assertOk();

        $this->assertSame(1, DB::table('analytics_events')->where('name', 'detail')->count());
        $this->assertSame(1, (int) $property->fresh()->view_count);
    }

    /**
     * The counter and the event table feed different screens — the dashboard
     * total and the per-day chart — and a view that landed in one but not the
     * other would show a listing whose headline number and whose graph disagree.
     */
    public function test_the_counter_and_the_event_log_never_disagree(): void
    {
        $property = $this->listing();

        foreach (range(1, 4) as $i) {
            // A fresh session each time, as four different people would be.
            $this->flushSession();
            $this->get(route('property.show', $property))->assertOk();
        }

        $this->assertSame(4, (int) $property->fresh()->view_count);
        $this->assertSame(4, DB::table('analytics_events')->where('name', 'detail')->count());
    }

    public function test_a_crawler_is_not_counted(): void
    {
        $property = $this->listing();

        $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
            ->get(route('property.show', $property))
            ->assertOk();

        // A lister who opens their dashboard to a thousand views that were all
        // Googlebot concludes the numbers are nonsense and never looks again.
        $this->assertSame(0, (int) $property->fresh()->view_count);
        $this->assertSame(0, DB::table('analytics_events')->count());
    }

    public function test_a_request_with_no_user_agent_at_all_is_not_counted(): void
    {
        $property = $this->listing();

        $this->withHeader('User-Agent', '')->get(route('property.show', $property));

        $this->assertSame(0, DB::table('analytics_events')->count());
    }

    /**
     * A draft 404s, and the 404 has to happen first. Otherwise probing uuids
     * would inflate a listing's numbers before it was ever on the market — and
     * the inflated figure would be the one the lister saw on day one.
     */
    public function test_a_listing_that_is_not_public_records_nothing(): void
    {
        $property = $this->listing('draft');

        $this->get(route('property.show', $property))->assertNotFound();

        $this->assertSame(0, DB::table('analytics_events')->count());
        $this->assertSame(0, (int) $property->fresh()->view_count);
    }

    public function test_searching_is_counted_but_panning_the_map_is_not(): void
    {
        $this->listing();

        $this->get(route('search'))->assertOk();

        // The map refetches the result list on every drag. Counting those would
        // report one seeker who moved the map twenty times as twenty searches,
        // and every rate measured against searches would collapse.
        $this->get(route('search', ['fragment' => 1]))->assertOk();
        $this->get(route('search', ['fragment' => 1]))->assertOk();

        $this->assertSame(1, DB::table('analytics_events')->where('name', 'search')->count());
    }

    public function test_a_search_that_finds_nothing_is_recorded_as_such(): void
    {
        $this->get(route('search', ['q' => 'nowhere-at-all']))->assertOk();

        $row = DB::table('analytics_events')->where('name', 'results')->first();

        // The most actionable number on the funnel screen: where seekers are
        // looking and supply is not.
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->value);
    }

    /**
     * `interactions` is unique on (user, property, kind), so a seeker who rings
     * and then messages overwrites their own row. That is correct for the
     * standing relationship and useless for "contact initiations by mode",
     * which is what FR-M13-01 asks for.
     */
    public function test_contacting_twice_by_different_routes_records_both(): void
    {
        $property = $this->listing();
        $seeker = $this->seeker();

        $this->actingAs($seeker)->post(route('interact.contact', $property), ['mode' => 'phone']);
        $this->actingAs($seeker)->post(route('interact.contact', $property), ['mode' => 'whatsapp']);

        $this->assertSame(1, Interaction::where('kind', 'contact')->count());
        $this->assertSame(2, DB::table('analytics_events')->where('name', 'contact')->count());

        $modes = DB::table('analytics_events')->where('name', 'contact')->pluck('context')->sort()->values();
        $this->assertSame(['phone', 'whatsapp'], $modes->all());
    }

    // =================================================================== consent

    public function test_nobody_is_followed_before_they_agree(): void
    {
        $property = $this->listing();

        $this->get(route('property.show', $property))->assertOk();

        $event = DB::table('analytics_events')->where('name', 'detail')->first();

        // The event exists — a total is not personal data, and the lister's
        // numbers have to be true rather than true-of-the-consenting-minority.
        $this->assertNotNull($event);
        // But nothing ties it to a person.
        $this->assertNull($event->visitor_id);
    }

    public function test_agreeing_sets_a_visitor_id_and_it_is_then_attached(): void
    {
        $property = $this->listing();

        $response = $this->post(route('consent'), ['choice' => Consent::ANALYTICS]);
        $response->assertRedirect();

        $visitor = $this->visitorCookieFrom($response);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $visitor);

        $this->withUnencryptedCookies([
            Consent::COOKIE => Consent::ANALYTICS,
            Consent::VISITOR_COOKIE => $visitor,
        ])->get(route('property.show', $property))->assertOk();

        $this->assertSame($visitor, DB::table('analytics_events')->where('name', 'detail')->value('visitor_id'));
    }

    /**
     * Declining has to remove the identifier, not merely stop issuing new ones.
     * An id that survives somebody turning tracking off is exactly the thing
     * turning it off is supposed to remove, and leaving it would make the
     * banner dishonest.
     */
    public function test_declining_clears_an_identifier_that_already_exists(): void
    {
        $accepted = $this->post(route('consent'), ['choice' => Consent::ANALYTICS]);
        $visitor = $this->visitorCookieFrom($accepted);

        $declined = $this->withUnencryptedCookies([
            Consent::COOKIE => Consent::ANALYTICS,
            Consent::VISITOR_COOKIE => $visitor,
        ])->post(route('consent'), ['choice' => Consent::ESSENTIAL]);

        $cookie = collect($declined->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === Consent::VISITOR_COOKIE);

        $this->assertNotNull($cookie, 'Declining must send a cookie that clears the visitor id.');
        $this->assertEmpty($cookie->getValue());
    }

    public function test_the_banner_shows_until_a_choice_is_made_and_not_after(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('Essential only');

        $this->withUnencryptedCookie(Consent::COOKIE, Consent::ESSENTIAL)
            ->get(route('home'))->assertOk()->assertDontSee('Essential only');

        $this->withUnencryptedCookie(Consent::COOKIE, Consent::ANALYTICS)
            ->get(route('home'))->assertOk()->assertDontSee('Essential only');
    }

    public function test_an_unanswered_banner_means_essential_only(): void
    {
        // The default is a refusal, not a pending yes (FR-M1-02).
        $this->assertFalse(Consent::allowsAnalytics());
        $this->assertFalse(Consent::answered());
        $this->assertSame(Consent::ESSENTIAL, Consent::current());
    }

    public function test_a_forged_consent_value_does_not_grant_anything(): void
    {
        $property = $this->listing();

        $this->withUnencryptedCookies([
            Consent::COOKIE => 'yes-please-track-me',
            Consent::VISITOR_COOKIE => str_repeat('a', 32),
        ])->get(route('property.show', $property))->assertOk();

        // Anything that is not the exact opt-in string falls back to essential.
        $this->assertNull(DB::table('analytics_events')->where('name', 'detail')->value('visitor_id'));
    }

    public function test_a_malformed_visitor_id_is_refused_rather_than_stored(): void
    {
        $property = $this->listing();

        $this->withUnencryptedCookies([
            Consent::COOKIE => Consent::ANALYTICS,
            Consent::VISITOR_COOKIE => '<script>alert(1)</script>',
        ])->get(route('property.show', $property))->assertOk();

        $this->assertNull(DB::table('analytics_events')->where('name', 'detail')->value('visitor_id'));
    }

    // ==================================================================== beacon

    public function test_the_browser_may_only_report_the_two_events_it_is_trusted_with(): void
    {
        $property = $this->listing();

        $this->postJson(route('events'), [
            'name' => 'tour_open', 'property' => $property->uuid,
        ])->assertNoContent();

        // Anything else is refused. An endpoint that stores whatever name it is
        // handed is an endpoint somebody fills with whatever they like.
        $this->postJson(route('events'), [
            'name' => 'detail', 'property' => $property->uuid,
        ])->assertStatus(422);

        $this->postJson(route('events'), [
            'name' => 'contact', 'property' => $property->uuid,
        ])->assertStatus(422);

        $this->assertSame(['tour_open'], DB::table('analytics_events')->pluck('name')->all());
    }

    public function test_a_dwell_reading_is_capped_rather_than_believed(): void
    {
        $property = $this->listing();

        $this->postJson(route('events'), [
            'name' => 'tour_dwell', 'property' => $property->uuid, 'value' => 999999,
        ])->assertStatus(422);

        $this->postJson(route('events'), [
            'name' => 'tour_dwell', 'property' => $property->uuid, 'value' => 240,
        ])->assertNoContent();

        $this->assertSame(240, (int) DB::table('analytics_events')->where('name', 'tour_dwell')->value('value'));
    }

    public function test_the_beacon_will_not_confirm_that_a_draft_exists(): void
    {
        $property = $this->listing('draft');

        // 204, the same as success: the response must not become a way to test
        // whether a uuid is a real listing (SEC-03).
        $this->postJson(route('events'), [
            'name' => 'tour_open', 'property' => $property->uuid,
        ])->assertNoContent();

        $this->assertSame(0, DB::table('analytics_events')->count());
    }

    // =============================================================== the reports

    public function test_a_lister_sees_the_four_numbers_the_requirement_asks_for(): void
    {
        $lister = $this->lister();
        $property = $this->listing('published', $lister);
        $seeker = $this->seeker();

        $this->get(route('property.show', $property));
        $this->flushSession();
        $this->get(route('property.show', $property));

        $this->actingAs($seeker)->post(route('interact.contact', $property), ['mode' => 'whatsapp']);
        Interaction::create(['user_id' => $seeker->id, 'property_id' => $property->id, 'kind' => 'save']);

        $this->postJson(route('events'), ['name' => 'tour_open', 'property' => $property->uuid]);
        $this->postJson(route('events'), ['name' => 'tour_dwell', 'property' => $property->uuid, 'value' => 120]);

        $stats = (new ListingAnalytics)->for($property->fresh());

        $this->assertSame(2, $stats['views']);
        $this->assertSame(1, $stats['saves']['total']);
        $this->assertSame(1, $stats['contacts']['total']);
        $this->assertSame(1, $stats['contacts']['by_mode']['whatsapp']);
        $this->assertSame(0, $stats['contacts']['by_mode']['phone']);
        $this->assertSame(1, $stats['tours']);
        $this->assertSame(120, $stats['dwell']['median']);
    }

    /**
     * One tab left open drags a mean past the target on its own, and the
     * resulting figure would say the tour is working when nobody watched it.
     */
    public function test_dwell_uses_the_median_so_one_abandoned_tab_cannot_move_it(): void
    {
        $property = $this->listing();

        foreach ([30, 35, 40, 45, 3600] as $seconds) {
            DB::table('analytics_events')->insert([
                'name' => 'tour_dwell', 'property_id' => $property->id,
                'value' => $seconds, 'occurred_at' => now(),
            ]);
        }

        $stats = (new ListingAnalytics)->for($property);

        // The mean of these is 750 seconds, which would clear O2's 90-second
        // target on the strength of a single abandoned tab.
        $this->assertSame(40, $stats['dwell']['median']);
    }

    /**
     * "56 views this month, 4 since it went up" is not a small discrepancy to
     * a lister — it is proof the screen is broken, and they stop believing the
     * other three numbers as well.
     */
    public function test_the_lifetime_figure_is_never_smaller_than_the_window(): void
    {
        $property = $this->listing();

        foreach (range(1, 3) as $i) {
            $this->flushSession();
            $this->get(route('property.show', $property));
        }

        // The counter and the events have come apart — a swallowed increment
        // failure, or somebody resetting the column by hand.
        DB::table('properties')->where('id', $property->id)->update(['view_count' => 1]);

        $stats = (new ListingAnalytics)->for($property->fresh());

        $this->assertSame(3, $stats['views']);
        $this->assertGreaterThanOrEqual($stats['views'], $stats['views_all']);
    }

    public function test_a_listers_analytics_are_not_visible_to_another_lister(): void
    {
        $mine = $this->listing('published', $this->lister());
        $someoneElse = $this->lister();

        $this->actingAs($someoneElse)
            ->get(route('lister.listings.analytics', $mine))
            ->assertForbidden();

        $this->actingAs($mine->lister)
            ->get(route('lister.listings.analytics', $mine))
            ->assertOk()
            ->assertSee('How this listing is doing');
    }

    public function test_the_funnel_screen_reports_both_sides(): void
    {
        $lister = $this->lister();
        $property = $this->listing('published', $lister);

        $this->get(route('search'));
        $this->get(route('property.show', $property));

        $admin = $this->lister();
        $admin->forceFill(['is_staff' => true, 'staff_role' => 'admin'])->save();

        $this->actingAs($admin)->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('The seeker funnel')
            ->assertSee('The lister funnel')
            // The honesty panel is not optional furniture: it is what stops a
            // consented-only rate being read as the market's rate.
            ->assertSee('Journeys cover', false);
    }

    public function test_the_contact_rate_is_a_real_number_now(): void
    {
        $property = $this->listing();
        $seeker = $this->seeker();

        foreach (range(1, 4) as $i) {
            $this->flushSession();
            $this->get(route('property.show', $property));
        }

        $this->actingAs($seeker)->post(route('interact.contact', $property), ['mode' => 'phone']);

        // Structurally null before M13, because view_count was a column nothing
        // incremented and the denominator was always zero.
        $this->assertSame(25.0, (new Funnel)->seeker()['contact_rate']);
    }

    public function test_the_lister_funnel_is_derived_rather_than_instrumented(): void
    {
        $lister = $this->lister();
        $property = $this->listing('published', $lister);

        Order::create([
            'uuid' => Str::uuid(), 'user_id' => $lister->id,
            'item_type' => 'scan_3d', 'amount' => 150000, 'currency' => 'NGN',
            'state' => 'paid', 'paid_at' => now(),
            'paystack_reference' => 'ref_'.Str::random(10),
        ]);

        $property->update(['submitted_at' => now()->subDay()]);

        $funnel = (new Funnel)->lister();
        $counts = collect($funnel['steps'])->pluck('count', 'name');

        $this->assertSame(1, $counts['Registered as a lister']);
        $this->assertSame(1, $counts['Passed identity checks']);
        $this->assertSame(1, $counts['Submitted a listing']);
        $this->assertSame(1, $counts['Got one published']);
        $this->assertSame(1, $counts['Bought a 3D capture']);

        // Not one analytics event was written for any of that: every step is a
        // timestamp the platform already kept.
        $this->assertSame(0, DB::table('analytics_events')->count());
    }

    // =================================================================== rollup

    public function test_rolling_up_twice_does_not_double_the_numbers(): void
    {
        $property = $this->listing();

        foreach (range(1, 3) as $i) {
            $this->flushSession();
            $this->get(route('property.show', $property));
        }

        // An aggregate built by incrementing silently doubles the first time a
        // cron overlaps, with nothing in the data to say it happened.
        $this->artisan('agentpro:roll-up-analytics')->assertSuccessful();
        $this->artisan('agentpro:roll-up-analytics')->assertSuccessful();

        $this->assertSame(1, DB::table('analytics_daily')->where('name', 'detail')->count());
        $this->assertSame(3, (int) DB::table('analytics_daily')->where('name', 'detail')->value('events'));
    }

    public function test_the_reports_survive_the_raw_events_being_pruned(): void
    {
        $property = $this->listing();

        // Traffic from well outside the retention window.
        DB::table('analytics_events')->insert([
            ['name' => 'detail', 'property_id' => $property->id, 'occurred_at' => now()->subDays(120)],
            ['name' => 'detail', 'property_id' => $property->id, 'occurred_at' => now()->subDays(120)],
        ]);

        // Rolled up on the day it happened, as the schedule would have.
        $this->travelTo(now()->subDays(119));
        $this->artisan('agentpro:roll-up-analytics')->assertSuccessful();
        $this->travelBack();

        $this->artisan('agentpro:roll-up-analytics')->assertSuccessful();

        // The raw rows are gone — that is the point, under data minimisation.
        $this->assertSame(0, DB::table('analytics_events')->where('occurred_at', '<', now()->subDays(100))->count());
        // The daily totals that every report reads are not.
        $this->assertSame(2, (int) DB::table('analytics_daily')->where('name', 'detail')->sum('events'));
    }

    /**
     * A platform-wide row has a null property_id, and MariaDB treats nulls as
     * distinct in a unique index — so without the generated column standing in
     * for it, a re-run would quietly stack duplicate rows that every dashboard
     * then sums.
     */
    public function test_platform_wide_rollup_rows_cannot_duplicate(): void
    {
        $this->get(route('search'));

        $this->artisan('agentpro:roll-up-analytics')->assertSuccessful();
        $this->artisan('agentpro:roll-up-analytics')->assertSuccessful();

        $this->assertSame(1, DB::table('analytics_daily')
            ->where('name', 'search')->whereNull('property_id')->count());

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('analytics_daily')->insert([
            'day' => now()->toDateString(), 'name' => 'search', 'property_id' => null,
            'events' => 99, 'consented' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ================================================================== failsafe

    /**
     * Analytics is the least important thing on any page it appears on, and a
     * seeker seeing an error because a counter could not be written would be
     * the measurement destroying the thing it measures.
     */
    public function test_a_broken_event_store_does_not_break_the_listing_page(): void
    {
        $property = $this->listing();

        DB::statement('DROP TABLE analytics_events');

        $this->get(route('property.show', $property))->assertOk();
    }

    // ------------------------------------------------------------------ helpers

    private function visitorCookieFrom($response): string
    {
        $cookie = collect($response->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === Consent::VISITOR_COOKIE);

        $this->assertNotNull($cookie, 'Accepting analytics must issue a visitor id.');

        return $cookie->getValue();
    }
}
