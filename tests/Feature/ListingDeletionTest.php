<?php

namespace Tests\Feature;

use App\Enums\LifecycleState;
use App\Models\Area;
use App\Models\Order;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deleting a draft, and the three ways that must not become deleting anything
 * else.
 *
 * PropertyPolicy has allowed this since the first commit — owner, draft — and
 * there was never a route to it, so a listing begun by mistake could be
 * abandoned but not removed. For an administrator it was worse than untidy:
 * their own listings appear nowhere in the console they work in, and the
 * account menu that would have taken them to the lister dashboard is hidden
 * from staff.
 */
class ListingDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        return User::forceCreate(array_merge([
            'uuid' => Str::uuid(),
            'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(8)).'@example.test',
            'password' => 'not-used-here',
            'category' => 'sellers_agent',
            'verification_state' => 'verified',
            'verified_at' => now(),
        ], $attrs));
    }

    /** An administrator: staff, and a seeker by category, which is the point. */
    private function admin(): User
    {
        return $this->user([
            'category' => 'seeker',
            'is_staff' => true,
            'staff_role' => 'admin',
            'verification_state' => 'unverified',
            'verified_at' => null,
        ]);
    }

    private function listing(User $owner, string $state = 'draft'): Property
    {
        $area = Area::firstOrCreate(['slug' => 'ikoyi'], [
            'name' => 'Ikoyi', 'city' => 'Lagos', 'state' => 'Lagos', 'is_scan_coverage' => true,
        ]);

        return Property::create([
            'uuid' => Str::uuid(),
            'lister_id' => $owner->id,
            'area_id' => $area->id,
            'title' => 'A listing that was started',
            'slug' => 'started-'.Str::lower(Str::random(6)),
            'listing_type' => 'apartment',
            'intent' => 'rent',
            'build_status' => 'fully_built',
            'address_line' => '12 Bourdillon Road',
            'city' => 'Lagos',
            'state' => 'Lagos',
            'lat' => 6.4488,
            'lng' => 3.4390,
            'location' => DB::raw("ST_GeomFromText('POINT(3.4390 6.4488)')"),
            'lifecycle_state' => $state,
        ]);
    }

    public function test_a_lister_can_delete_their_own_draft(): void
    {
        $lister = $this->user();
        $draft = $this->listing($lister);

        $this->actingAs($lister)
            ->delete(route('lister.listings.destroy', $draft))
            ->assertRedirect(route('lister.dashboard'));

        $this->assertNull(Property::find($draft->id));
        $this->assertDatabaseHas('audit_events', ['action' => 'listing.deleted']);
    }

    /**
     * The gap this was written for: an administrator creates a listing, and
     * can then find it and remove it again.
     */
    public function test_an_admin_can_delete_the_draft_they_created(): void
    {
        $admin = $this->admin();
        $draft = $this->listing($admin);

        $this->actingAs($admin)
            ->delete(route('lister.listings.destroy', $draft))
            ->assertRedirect(route('lister.dashboard'));

        $this->assertNull(Property::find($draft->id));
    }

    /**
     * PropertyPolicy::before() grants a moderator every ability outright,
     * which is right for moderation and would be wrong here: it would let
     * staff delete a live listing through a route whose policy says
     * draft-only. Anything submitted is unlisted, never deleted.
     */
    public function test_nothing_past_draft_is_deletable_by_anybody(): void
    {
        foreach (['submitted', 'published', 'unpublished', 'sold'] as $state) {
            $owner = $this->user();
            $property = $this->listing($owner, $state);

            foreach ([$owner, $this->admin()] as $who) {
                $this->actingAs($who)->delete(route('lister.listings.destroy', $property));

                $this->assertNotNull(
                    Property::find($property->id),
                    'A '.$state.' listing was deleted by '.($who->is_staff ? 'an admin' : 'its owner'),
                );
            }
        }
    }

    /**
     * orders.property_id is nullOnDelete, so deleting a property with an order
     * against it leaves money in the ledger pointing at nothing. A draft should
     * never have one; "should never" is what makes it worth checking before
     * something irreversible.
     */
    public function test_a_draft_with_money_against_it_is_refused(): void
    {
        $lister = $this->user();
        $draft = $this->listing($lister);

        Order::create([
            'uuid' => Str::uuid(),
            'user_id' => $lister->id,
            'property_id' => $draft->id,
            'item_type' => 'scan_3d',
            'amount' => 150000,
            'currency' => 'NGN',
            'state' => 'paid',
            'paid_at' => now(),
        ]);

        $this->actingAs($lister)
            ->delete(route('lister.listings.destroy', $draft))
            ->assertSessionHasErrors('listing');

        $this->assertNotNull(Property::find($draft->id));
    }

    public function test_one_lister_cannot_delete_anothers_draft(): void
    {
        $draft = $this->listing($this->user());

        $this->actingAs($this->user())
            ->delete(route('lister.listings.destroy', $draft))
            ->assertForbidden();

        $this->assertNotNull(Property::find($draft->id));
    }

    /**
     * The route alone is not the fix. An administrator reaches their listings
     * through the account menu, which is hidden from anyone canList() is false
     * for — and that is every administrator, because its other job is deciding
     * who has to pass identity verification.
     */
    public function test_an_admin_is_offered_their_listings_without_being_asked_to_verify(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('home'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('lister.dashboard'), $html);
        $this->assertStringContainsString(route('lister.listings.create'), $html);
        $this->assertStringNotContainsString(route('verify.show'), $html, 'Staff were pushed into lister verification.');
    }

    /** And finds them in the console, which is where an administrator works. */
    public function test_the_console_can_be_filtered_to_an_admins_own_listings(): void
    {
        $admin = $this->admin();
        $mine = $this->listing($admin);
        $theirs = $this->listing($this->user());

        $html = $this->actingAs($admin)
            ->get(route('admin.listings', ['mine' => 1]))
            ->assertOk()
            ->assertSee($mine->title, false)
            ->getContent();

        $this->assertStringNotContainsString($theirs->slug, $html);
        $this->assertStringContainsString(route('lister.listings.edit', $mine), $html);
    }

    /**
     * Edit on every row, not only the administrator's own.
     *
     * The first cut of this screen offered it on your own rows only, on the
     * argument that moderation acts on other people's listings through the
     * review screen and leaves a reason behind. The decision went the other
     * way, and it costs nothing in authorisation: PropertyPolicy::before()
     * grants a moderator every ability on a listing already, so the link
     * exposes what the policy has always said rather than widening it.
     */
    public function test_edit_is_offered_on_every_row(): void
    {
        $admin  = $this->admin();
        $mine   = $this->listing($admin);
        $theirs = $this->listing($this->user(), 'published');

        $html = $this->actingAs($admin)->get(route('admin.listings'))->assertOk()->getContent();

        foreach (['their own' => $mine, 'somebody else’s' => $theirs] as $whose => $property) {
            $this->assertStringContainsString(
                route('lister.listings.edit', $property),
                $html,
                'An admin was offered no way to edit '.$whose.' listing.',
            );
        }

        // And the link has to work. A row offering an action that answers 403
        // is worse than a row offering none.
        $this->actingAs($admin)->get(route('lister.listings.edit', $theirs))->assertOk();
    }

    /**
     * Delete followed Edit, on drafts and nothing else.
     *
     * A draft is the one state that has never been public and can have nothing
     * paid against it, so what a delete destroys is work no visitor has seen.
     * Everything past it is unlisted instead — see the test above, which is
     * what stops before() turning this into a way to remove a live listing.
     */
    public function test_delete_is_offered_on_every_draft_and_no_other_state(): void
    {
        $admin     = $this->admin();
        $mine      = $this->listing($admin);
        $theirs    = $this->listing($this->user());
        $published = $this->listing($this->user(), 'published');

        $html = $this->actingAs($admin)->get(route('admin.listings'))->assertOk()->getContent();

        foreach (['their own' => $mine, 'somebody else’s' => $theirs] as $whose => $draft) {
            $this->assertStringContainsString(
                route('lister.listings.destroy', $draft),
                $html,
                'An admin was offered no way to delete '.$whose.' draft.',
            );
        }

        $this->assertStringNotContainsString(
            route('lister.listings.destroy', $published),
            $html,
            'A published listing was offered a delete form.',
        );
    }

    /**
     * And the delete works from there. The redirect matters as much as the
     * delete: staff clearing up somebody else's draft came from the console,
     * and the lister dashboard they would otherwise land on lists properties
     * they do not have.
     */
    public function test_an_admin_deleting_somebody_elses_draft_lands_back_in_the_console(): void
    {
        $admin = $this->admin();
        $draft = $this->listing($lister = $this->user());

        $this->actingAs($admin)
            ->delete(route('lister.listings.destroy', $draft))
            ->assertRedirect(route('admin.listings'));

        $this->assertNull(Property::find($draft->id));

        // Nobody is notified, so the audit log is the only record that it
        // happened. It names the listing and who it belonged to.
        $this->assertDatabaseHas('audit_events', [
            'action'  => 'listing.deleted',
            'actor_id' => $admin->id,
        ]);
        $this->assertStringContainsString($lister->name, session('status'));
    }
}
