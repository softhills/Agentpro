<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\Area;
use App\Models\Property;
use App\Models\SavedSearch;
use App\Models\Unit;
use App\Models\User;
use App\Queries\PropertySearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Amenity and area administration (FR-M12-06).
 *
 * The CRUD is the boring part. What these tests are actually about is that both
 * of these taxonomies are referenced by *slug* — by PropertySearch, by every
 * shared filter link, and by the criteria JSON of every saved search — so an
 * edit that treats the slug as an internal detail silently changes what
 * thousands of saved searches mean. Nothing errors; results just quietly go
 * wrong, and from the outside it looks like the market went quiet.
 */
class TaxonomyTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        return User::forceCreate(array_merge([
            'uuid' => Str::uuid(), 'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'sellers_agent',
            'verification_state' => 'verified', 'verified_at' => now(),
        ], $attrs));
    }

    private function moderator(): User
    {
        return $this->user(['category' => 'seeker', 'is_staff' => true, 'staff_role' => 'moderator']);
    }

    private function area(string $name = 'Ikoyi', string $slug = 'ikoyi'): Area
    {
        return Area::firstOrCreate(['slug' => $slug], [
            'name' => $name, 'city' => 'Lagos', 'state' => 'Lagos', 'is_scan_coverage' => false,
        ]);
    }

    private function amenity(string $name, string $slug, string $group = 'utilities'): Amenity
    {
        return Amenity::create(['name' => $name, 'slug' => $slug, 'group' => $group, 'is_filterable' => true]);
    }

    private function property(?Area $area = null): Property
    {
        $property = Property::create([
            'uuid' => Str::uuid(), 'lister_id' => $this->user()->id, 'area_id' => ($area ?? $this->area())->id,
            'title' => '3-Bed Apartment, Ikoyi', 'slug' => 'ikoyi-'.Str::lower(Str::random(6)),
            'listing_type' => 'apartment', 'intent' => 'rent', 'build_status' => 'fully_built',
            'address_line' => '12 Bourdillon Road', 'city' => 'Lagos', 'state' => 'Lagos',
            'lat' => 6.4488, 'lng' => 3.4390,
            'location' => DB::raw("ST_GeomFromText('POINT(3.4390 6.4488)')"),
            'lifecycle_state' => 'published', 'published_at' => now(),
        ]);

        Unit::create([
            'uuid' => Str::uuid(), 'property_id' => $property->id, 'is_primary' => true,
            'price' => 7500000, 'price_period' => 'year', 'bedrooms' => 3,
        ]);

        return $property->fresh();
    }

    private function savedSearch(array $criteria): SavedSearch
    {
        return SavedSearch::create([
            'uuid' => Str::uuid(), 'user_id' => $this->user()->id,
            'name' => 'My search', 'criteria' => $criteria, 'frequency' => 'daily',
        ]);
    }

    // ------------------------------------------------------------------ access

    public function test_the_taxonomy_screen_is_staff_only(): void
    {
        $this->actingAs($this->user())->get(route('admin.taxonomy'))->assertNotFound();
        $this->actingAs($this->moderator())->get(route('admin.taxonomy'))->assertOk();
    }

    // --------------------------------------------------------------- amenities

    public function test_an_amenity_can_be_added_without_a_deployment(): void
    {
        $this->actingAs($this->moderator())
            ->post(route('admin.amenities.store'), [
                'name' => 'Borehole', 'group' => 'Utilities', 'is_filterable' => '1',
            ])
            ->assertRedirect();

        $amenity = Amenity::where('name', 'Borehole')->firstOrFail();

        $this->assertSame('borehole', $amenity->slug);
        $this->assertSame('utilities', $amenity->group, 'groups are folded to one case so the list does not split');
        $this->assertTrue($amenity->is_filterable);
        $this->assertDatabaseHas('audit_events', ['action' => 'amenity.created', 'subject_id' => $amenity->id]);
    }

    /**
     * The test this whole feature exists for.
     *
     * A slug is a foreign key held in a JSON column by every seeker who saved a
     * search. Renaming it in place breaks none of them loudly and all of them
     * quietly.
     */
    public function test_renaming_an_amenity_slug_carries_saved_searches_with_it(): void
    {
        $amenity = $this->amenity('Borehole', 'borehole');

        $affected = $this->savedSearch(['intent' => 'rent', 'amenities' => ['borehole', 'gated-estate']]);
        $untouched = $this->savedSearch(['intent' => 'sale', 'amenities' => ['gated-estate']]);

        $this->actingAs($this->moderator())
            ->put(route('admin.amenities.update', $amenity), [
                'name' => 'Borehole', 'group' => 'utilities', 'slug' => 'water-borehole', 'is_filterable' => '1',
            ])
            ->assertRedirect();

        $this->assertSame(
            ['water-borehole', 'gated-estate'],
            $affected->fresh()->criteria['amenities'],
        );

        $this->assertSame(['gated-estate'], $untouched->fresh()->criteria['amenities']);
    }

    /** And the rewritten search must still find the listing afterwards. */
    public function test_a_renamed_amenity_still_matches_the_listings_that_have_it(): void
    {
        $amenity = $this->amenity('Borehole', 'borehole');
        $property = $this->property();
        $property->amenities()->attach($amenity->id);

        $search = $this->savedSearch(['amenities' => ['borehole']]);

        $this->actingAs($this->moderator())->put(route('admin.amenities.update', $amenity), [
            'name' => 'Borehole', 'group' => 'utilities', 'slug' => 'water-borehole', 'is_filterable' => '1',
        ]);

        $found = (new PropertySearch($search->fresh()->criteria))->builder()->pluck('properties.id');

        $this->assertTrue($found->contains($property->id), 'the saved search must still return its listing');
    }

    public function test_renaming_only_the_display_name_leaves_saved_searches_alone(): void
    {
        $amenity = $this->amenity('Borehole', 'borehole');
        $search = $this->savedSearch(['amenities' => ['borehole']]);

        $this->actingAs($this->moderator())->put(route('admin.amenities.update', $amenity), [
            'name' => 'Borehole (private)', 'group' => 'utilities', 'slug' => 'borehole', 'is_filterable' => '1',
        ])->assertRedirect();

        $this->assertSame('Borehole (private)', $amenity->fresh()->name);
        $this->assertSame(['borehole'], $search->fresh()->criteria['amenities']);
    }

    public function test_a_slug_already_in_use_is_refused(): void
    {
        $this->amenity('Borehole', 'borehole');
        $other = $this->amenity('Gated estate', 'gated-estate', 'security');

        $this->actingAs($this->moderator())
            ->put(route('admin.amenities.update', $other), [
                'name' => 'Gated estate', 'group' => 'security', 'slug' => 'borehole',
            ])
            ->assertSessionHasErrors('slug');

        $this->assertSame('gated-estate', $other->fresh()->slug);
    }

    // ------------------------------------------------------------------ merges

    /**
     * The real-world operation: the list accumulates near-duplicates because
     * listers see whatever is on the form.
     */
    public function test_merging_amenities_keeps_the_listings_and_loses_the_duplicate(): void
    {
        $keep = $this->amenity('Borehole', 'borehole');
        $dupe = $this->amenity('Bore hole', 'bore-hole');

        $a = $this->property();
        $b = $this->property();
        $a->amenities()->attach($dupe->id);
        $b->amenities()->attach($dupe->id);

        $search = $this->savedSearch(['amenities' => ['bore-hole']]);

        $this->actingAs($this->moderator())
            ->post(route('admin.amenities.merge', $dupe), ['into' => $keep->id])
            ->assertRedirect();

        $this->assertNull(Amenity::find($dupe->id));
        $this->assertTrue($a->fresh()->amenities->contains($keep->id));
        $this->assertTrue($b->fresh()->amenities->contains($keep->id));
        $this->assertSame(['borehole'], $search->fresh()->criteria['amenities']);
    }

    /**
     * A listing tagged with both would violate the pivot's composite key.
     * Moving the row would fail; it has to be dropped as redundant instead.
     */
    public function test_merging_handles_a_listing_that_already_had_both(): void
    {
        $keep = $this->amenity('Borehole', 'borehole');
        $dupe = $this->amenity('Bore hole', 'bore-hole');

        $both = $this->property();
        $both->amenities()->attach([$keep->id, $dupe->id]);

        $this->actingAs($this->moderator())
            ->post(route('admin.amenities.merge', $dupe), ['into' => $keep->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame([$keep->id], $both->fresh()->amenities->pluck('id')->all());
        $this->assertSame(0, DB::table('amenity_property')->where('amenity_id', $dupe->id)->count());
    }

    /** A merge must not leave the surviving slug listed twice in one search. */
    public function test_merging_does_not_duplicate_a_slug_a_search_already_had(): void
    {
        $keep = $this->amenity('Borehole', 'borehole');
        $dupe = $this->amenity('Bore hole', 'bore-hole');

        $search = $this->savedSearch(['amenities' => ['borehole', 'bore-hole']]);

        $this->actingAs($this->moderator())->post(route('admin.amenities.merge', $dupe), ['into' => $keep->id]);

        $this->assertSame(['borehole'], $search->fresh()->criteria['amenities']);
    }

    // ---------------------------------------------------------------- deletion

    /**
     * The pivot cascades, so deleting an amenity in use would strip it from
     * every listing that had it, with no error and no way to find out which.
     */
    public function test_an_amenity_in_use_cannot_be_deleted(): void
    {
        $amenity = $this->amenity('Borehole', 'borehole');
        $property = $this->property();
        $property->amenities()->attach($amenity->id);

        $this->actingAs($this->moderator())
            ->delete(route('admin.amenities.destroy', $amenity))
            ->assertSessionHasErrors('amenity');

        $this->assertNotNull(Amenity::find($amenity->id));
        $this->assertTrue($property->fresh()->amenities->contains($amenity->id));
    }

    public function test_an_unused_amenity_can_be_deleted(): void
    {
        $amenity = $this->amenity('Never used', 'never-used');

        $this->actingAs($this->moderator())
            ->delete(route('admin.amenities.destroy', $amenity))
            ->assertRedirect();

        $this->assertNull(Amenity::find($amenity->id));
        $this->assertDatabaseHas('audit_events', ['action' => 'amenity.deleted']);
    }

    // ------------------------------------------------------------------- areas

    public function test_a_new_area_is_never_opened_for_capture_by_creating_it(): void
    {
        $this->actingAs($this->moderator())
            ->post(route('admin.areas.store'), [
                'name' => 'Gbagada', 'city' => 'Lagos', 'state' => 'Lagos',
                'centroid_lat' => '6.5540', 'centroid_lng' => '3.3890', 'default_zoom' => '14',
            ])
            ->assertRedirect();

        $area = Area::where('name', 'Gbagada')->firstOrFail();

        // Coverage commits the field team to servicing it, which is an
        // Operations decision — not a side effect of adding a name to a list.
        $this->assertFalse($area->is_scan_coverage);
        $this->assertSame('gbagada-lagos', $area->slug);
    }

    public function test_renaming_an_area_slug_carries_saved_searches_with_it(): void
    {
        $area = $this->area('Ikoyi', 'ikoyi');
        $affected = $this->savedSearch(['area' => 'ikoyi', 'intent' => 'rent']);
        $untouched = $this->savedSearch(['area' => 'lekki']);

        $this->actingAs($this->moderator())
            ->put(route('admin.areas.update', $area), [
                'name' => 'Ikoyi', 'slug' => 'ikoyi-lagos', 'city' => 'Lagos', 'state' => 'Lagos',
            ])
            ->assertRedirect();

        $this->assertSame('ikoyi-lagos', $affected->fresh()->criteria['area']);
        $this->assertSame('lekki', $untouched->fresh()->criteria['area']);
    }

    public function test_merging_areas_moves_listings_slots_and_searches(): void
    {
        $keep = $this->area('Ikoyi', 'ikoyi');
        $dupe = $this->area('Ikoyi Island', 'ikoyi-island');

        $property = $this->property($dupe);

        DB::table('technician_slots')->insert([
            'area_id' => $dupe->id, 'technician_id' => null,
            'slot_date' => now()->addDays(3)->toDateString(), 'slot_start' => '09:00:00',
            'capacity' => 1, 'booked' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $search = $this->savedSearch(['area' => 'ikoyi-island']);

        $this->actingAs($this->moderator())
            ->post(route('admin.areas.merge', $dupe), ['into' => $keep->id])
            ->assertRedirect();

        $this->assertNull(Area::find($dupe->id));
        $this->assertSame($keep->id, $property->fresh()->area_id);
        $this->assertSame('ikoyi', $search->fresh()->criteria['area']);
        // Capacity must follow the area, or it silently disappears.
        $this->assertSame(1, DB::table('technician_slots')->where('area_id', $keep->id)->count());
    }

    /**
     * area_id is nullOnDelete, so deleting an area with listings does not
     * remove them — it detaches them. They stay published and findable by every
     * filter except the area they are in, which is the worst outcome because
     * nothing looks broken.
     */
    public function test_an_area_with_listings_cannot_be_deleted(): void
    {
        $area = $this->area();
        $property = $this->property($area);

        $this->actingAs($this->moderator())
            ->delete(route('admin.areas.destroy', $area))
            ->assertSessionHasErrors('area');

        $this->assertNotNull(Area::find($area->id));
        $this->assertSame($area->id, $property->fresh()->area_id);
    }

    public function test_an_area_with_capture_slots_cannot_be_deleted(): void
    {
        $area = $this->area('Empty', 'empty-area');

        DB::table('technician_slots')->insert([
            'area_id' => $area->id, 'technician_id' => null,
            'slot_date' => now()->addDay()->toDateString(), 'slot_start' => '09:00:00',
            'capacity' => 1, 'booked' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->moderator())
            ->delete(route('admin.areas.destroy', $area))
            ->assertSessionHasErrors('area');

        $this->assertNotNull(Area::find($area->id));
    }

    // ------------------------------------------------------------ vocabularies

    /**
     * The fixed vocabularies are shown but must expose no write route: they
     * carry legal weight and feed reporting, so they change through review and
     * a release.
     */
    public function test_the_fixed_vocabularies_are_read_only(): void
    {
        $this->actingAs($this->moderator())
            ->get(route('admin.taxonomy'))
            ->assertOk()
            ->assertSee('certificate_of_occupancy', false)
            ->assertSee('Rejection reasons', false);

        $writes = collect(app('router')->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'taxonomy'))
            ->filter(fn ($r) => array_intersect($r->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']))
            ->map(fn ($r) => $r->uri());

        foreach ($writes as $uri) {
            $this->assertTrue(
                str_contains($uri, 'amenities') || str_contains($uri, 'areas'),
                'the only writable taxonomies are amenities and areas; found '.$uri
            );
        }
    }
}
