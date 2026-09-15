<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\Area;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The data a production install cannot open without.
 *
 * Areas and amenities are not fixtures. They are the options in the listing
 * form's area and amenity fields, the facets in search, the rows behind /areas,
 * and the flag that decides where 3D capture can be booked — and they used to
 * live inside the development seeder, which refuses to run in production
 * because it creates accounts whose password is the word "password". A correct
 * guard took the reference data down with it, and a deployment that followed
 * the instructions got a listing form nobody could complete.
 */
class ReferenceDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_populates_the_taxonomy_a_listing_form_needs(): void
    {
        $this->assertSame(0, Area::count());

        $this->seed(ReferenceDataSeeder::class);

        $this->assertGreaterThan(0, Area::count());
        $this->assertGreaterThan(0, Amenity::count());

        // FR-M4-05: 3D capture is bookable only where there is coverage, so
        // both answers have to exist or the refusal can never be demonstrated.
        $this->assertTrue(Area::where('is_scan_coverage', true)->exists());
        $this->assertTrue(Area::where('is_scan_coverage', false)->exists());
    }

    /** A deploy runs it every time. Twice must equal once. */
    public function test_running_it_again_changes_nothing(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $areas     = Area::count();
        $amenities = Amenity::count();

        $this->seed(ReferenceDataSeeder::class);

        $this->assertSame($areas, Area::count());
        $this->assertSame($amenities, Amenity::count());
    }

    /**
     * It must not undo an administrator.
     *
     * This is why it seeds only an empty table rather than doing firstOrCreate
     * on the slug. Neither model soft-deletes, so a row removed deliberately
     * through the taxonomy console is indistinguishable from one that was never
     * seeded — and the convenient version of idempotency would resurrect it on
     * every deployment, silently, for ever.
     */
    public function test_it_does_not_resurrect_something_an_admin_deleted(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $doomed = Amenity::where('slug', 'gym')->firstOrFail();
        $doomed->delete();

        $renamed = Area::where('slug', 'yaba')->firstOrFail();
        $renamed->update(['name' => 'Yaba (Sabo)']);

        $this->seed(ReferenceDataSeeder::class);

        $this->assertNull(Amenity::where('slug', 'gym')->first(), 'A deleted amenity came back.');
        $this->assertSame('Yaba (Sabo)', Area::where('slug', 'yaba')->first()->name, 'A renamed area was overwritten.');
    }

    /**
     * It is the one seeder a deployment is told to run, so it has to run in the
     * environment a deployment runs in — unlike DatabaseSeeder, which refuses.
     */
    public function test_it_is_safe_in_production_unlike_the_development_seeder(): void
    {
        app()['env'] = 'production';

        // Invoked directly rather than through $this->seed(), which shells out
        // to `db:seed` — and that command prompts for confirmation in
        // production, so the test would be failing on the prompt rather than
        // on the thing it means to check.
        app(ReferenceDataSeeder::class)->run();

        $this->assertGreaterThan(0, Area::count());

        $this->expectException(\RuntimeException::class);
        app(\Database\Seeders\DatabaseSeeder::class)->run();
    }
}
