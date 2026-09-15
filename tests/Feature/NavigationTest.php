<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The header, per account type.
 *
 * These exist because the header used to carry one link per role laid out in a
 * row, and only one role ever got a link. A RealSure officer and a capture
 * technician could sign in to a console-shaped product and find no route to the
 * single screen their account exists for — both had to be told the URL, and the
 * technician console had no link anywhere in the application at all.
 *
 * The rule worth holding is narrower than "there are links": every link an
 * account is shown is a link that account can actually open. A menu offering a
 * console that 404s is worse than no menu, because the person now believes
 * their access is broken rather than knowing it was never granted.
 */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        return User::forceCreate(array_merge([
            'uuid' => Str::uuid(),
            'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x',
            'category' => 'seeker',
            'verification_state' => 'verified',
            'verified_at' => now(),
        ], $attrs));
    }

    private function staff(string $role): User
    {
        return $this->user(['is_staff' => true, 'staff_role' => $role]);
    }

    /** Every href the header offers this account, deduplicated. */
    private function headerLinks(User $user): array
    {
        $html = $this->actingAs($user)->get(route('home'))->assertOk()->getContent();

        $header = Str::before(Str::after($html, '<header class="nav">'), '</header>');

        preg_match_all('/href="([^"]+)"/', $header, $matches);

        return array_values(array_unique($matches[1]));
    }

    public function test_a_technician_is_given_a_link_to_their_assignments(): void
    {
        $links = $this->headerLinks($this->staff('technician'));

        $this->assertContains(route('technician.assignments'), $links);
    }

    public function test_a_realsure_officer_is_given_a_link_to_their_console(): void
    {
        $links = $this->headerLinks($this->staff('realsure_officer'));

        $this->assertContains(route('realsure.queue'), $links);
        // Not a moderator, and the menu must not pretend otherwise.
        $this->assertNotContains(route('admin.queue'), $links);
    }

    public function test_a_moderator_is_given_the_queue_and_the_listings_screen(): void
    {
        $links = $this->headerLinks($this->staff('moderator'));

        $this->assertContains(route('admin.queue'), $links);
        $this->assertContains(route('admin.listings'), $links);
    }

    /** isStaff() answers true for every role when the role is admin. */
    public function test_an_admin_is_given_all_three_consoles(): void
    {
        $links = $this->headerLinks($this->staff('admin'));

        $this->assertContains(route('admin.queue'), $links);
        $this->assertContains(route('realsure.queue'), $links);
        $this->assertContains(route('technician.assignments'), $links);
    }

    public function test_a_lister_is_given_their_listing_links(): void
    {
        $links = $this->headerLinks($this->user(['category' => 'sellers_agent']));

        $this->assertContains(route('lister.dashboard'), $links);
        $this->assertContains(route('lister.listings.create'), $links);
        $this->assertContains(route('lister.payouts'), $links);
    }

    public function test_a_seeker_is_offered_no_console_they_cannot_open(): void
    {
        $links = $this->headerLinks($this->user());

        foreach ([
            route('admin.queue'), route('admin.listings'),
            route('realsure.queue'), route('technician.assignments'),
            route('lister.dashboard'),
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $links);
        }
    }

    /**
     * NDPA ss. 34 and 38. A right to your own data that takes a support ticket
     * to exercise is not much of a right, so it is two clicks from any page.
     */
    public function test_every_signed_in_account_can_reach_its_own_data(): void
    {
        foreach (['seeker', 'sellers_agent'] as $category) {
            $links = $this->headerLinks($this->user(['category' => $category]));

            $this->assertContains(route('privacy.index'), $links);
            $this->assertContains(route('notifications.edit'), $links);
            $this->assertContains(route('saved-searches.index'), $links);
        }
    }

    /**
     * The point of the whole exercise: nothing in the header 404s.
     *
     * Walks every GET link the menu offers and opens it as that account. A
     * console link shown to somebody the route gate refuses is the exact bug
     * these menus were built to fix, and it is invisible until somebody clicks.
     */
    public function test_no_account_is_shown_a_link_it_cannot_open(): void
    {
        foreach (['technician', 'realsure_officer', 'moderator', 'admin'] as $role) {
            $user = $this->staff($role);

            foreach ($this->headerLinks($user) as $href) {
                $path = parse_url($href, PHP_URL_PATH) ?: '/';

                $status = $this->actingAs($user)->get($path)->getStatusCode();

                $this->assertContains($status, [200, 302], sprintf(
                    'A %s is offered %s in the header and gets a %d.', $role, $path, $status
                ));
            }
        }
    }

    /** FR-M2-09: the archive is reachable from every page, signed in or not. */
    public function test_the_sold_and_let_archive_is_linked_from_the_footer(): void
    {
        $this->get(route('home'))->assertOk()->assertSee(route('pages.closed'), false);
    }

    /**
     * Below 960px the main navigation bar is hidden by CSS, so for a guest on a
     * phone the markup has to carry the same links somewhere else — otherwise
     * the site has no navigation at all on the device most of its traffic
     * arrives on.
     */
    public function test_a_guest_on_a_phone_still_has_somewhere_to_go(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $header = Str::before(Str::after($html, '<header class="nav">'), '</header>');

        $this->assertStringContainsString('usermenu-guest', $header);
        $this->assertStringContainsString(route('login'), $header);
        $this->assertStringContainsString(route('pages.areas'), $header);
    }

    /**
     * Stylesheets and scripts carry a version, so a deploy is visible.
     *
     * There is no build step here and nothing fingerprints these filenames, so
     * css/app.css is a URL whose contents change and whose name does not. A
     * deployment went out, the server was confirmed to be serving the new file,
     * and the change was still invisible in the browser — which on a public
     * site is indistinguishable from not having deployed at all.
     */
    public function test_the_stylesheet_is_cache_busted(): void
    {
        $html = $this->get(route("home"))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            "/css\/app\.css\?v=\d+/",
            $html,
            "The stylesheet must carry a version, or browsers keep serving the copy they already have."
        );
    }
}