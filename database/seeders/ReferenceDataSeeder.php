<?php

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\Area;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The data the application cannot function without.
 *
 * Split out of DatabaseSeeder, which refuses to run in production because it
 * creates accounts whose password is the word "password". That guard was right
 * and it took these with it — and areas and amenities are not fixtures. They
 * are the options in the listing form's area and amenity fields, the facets in
 * search, the rows behind /areas, and the flag that decides where 3D capture
 * can be booked at all. A production install without them has a listing form
 * nobody can complete.
 *
 * SAFE IN PRODUCTION, AND SAFE TO RUN TWICE. This is the seeder a deployment
 * runs:
 *
 *     php artisan db:seed --class=ReferenceDataSeeder --force
 *
 * ONLY SEEDS AN EMPTY TABLE, and that is the important decision here. The
 * obvious alternative — firstOrCreate on the slug — would be idempotent in the
 * narrow sense and wrong in practice: neither model soft-deletes, so a row an
 * administrator deliberately removed through the taxonomy console is
 * indistinguishable from one that was never seeded, and every deployment would
 * quietly resurrect it. "Initial data" means initial. Once a table has
 * contents, it belongs to whoever has been administering it, and changes to it
 * are made in the console that records who made them.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAreas();
        $this->seedAmenities();
    }

    /**
     * Every area the site lists in, across the three cities it advertises, all
     * of them open for 3D capture.
     *
     * The ten from the spec arrived open and the rest were opened afterwards,
     * so a new install matching production means seeding them that way. Note
     * what that does NOT seed: capacity. Coverage decides whether the capture
     * upgrade can be bought in an area; the dates offered afterwards come from
     * technician_slots, and an area with none sends a lister who has already
     * paid to "No dates open". The development seeder fills a fortnight of
     * slots in every coverage area for exactly that reason; a production
     * install has to put real capacity behind each one on Coverage & capacity.
     *
     * Nothing here is closed any more, which the refusal path does not depend
     * on: ScanPurchaseTest builds its own area with coverage off rather than
     * borrowing one from the reference data.
     *
     * Centroids are approximate. They decide where the map opens for an area
     * and nothing else, and an operator can move one on Amenities & areas
     * without a deployment.
     */
    private function seedAreas(): void
    {
        if (Area::exists()) {
            $this->command?->line('  Areas already present — left alone.');

            return;
        }

        $areas = [
            ['Victoria Island', 'Lagos', 'Lagos', true, 6.4281, 3.4219],
            ['Ikoyi', 'Lagos', 'Lagos', true, 6.4488, 3.4390],
            ['Banana Island', 'Lagos', 'Lagos', true, 6.4419, 3.4470],
            ['Lekki Phase 1', 'Lagos', 'Lagos', true, 6.4478, 3.4723],
            ['Yaba', 'Lagos', 'Lagos', true, 6.5095, 3.3711],
            ['Maitama', 'Abuja', 'FCT', true, 9.0854, 7.4915],
            ['Asokoro', 'Abuja', 'FCT', true, 9.0392, 7.5250],
            ['Jabi', 'Abuja', 'FCT', true, 9.0640, 7.4200],
            ['Wuse II', 'Abuja', 'FCT', true, 9.0765, 7.4620],
            ['Central Business District', 'Abuja', 'FCT', true, 9.0400, 7.4900],
            ['Ajah', 'Lagos', 'Lagos', true, 6.4698, 3.5852],
            ['Gbagada', 'Lagos', 'Lagos', true, 6.5568, 3.3903],
            ['Gwarinpa', 'Abuja', 'FCT', true, 9.1090, 7.4030],

            /*
             * Enugu. Opened with the rest — the decision was Operations' to
             * make and they made it. The rule it does not touch is the one the
             * console applies: an area added by hand is still created closed,
             * because naming a place is not the same as committing the field
             * team to driving to it.
             */
            ['Independence Layout', 'Enugu', 'Enugu', true, 6.4335, 7.5160],
            ['Enugu GRA', 'Enugu', 'Enugu', true, 6.4453, 7.4968],
            ['New Haven', 'Enugu', 'Enugu', true, 6.4512, 7.4820],
            ['Ogui New Layout', 'Enugu', 'Enugu', true, 6.4423, 7.4890],
            ['Achara Layout', 'Enugu', 'Enugu', true, 6.4310, 7.4760],
            ['Trans-Ekulu', 'Enugu', 'Enugu', true, 6.4722, 7.5201],
            ['Abakpa Nike', 'Enugu', 'Enugu', true, 6.4790, 7.5370],
            ['Thinkers Corner', 'Enugu', 'Enugu', true, 6.4600, 7.5500],
            ['Uwani', 'Enugu', 'Enugu', true, 6.4365, 7.4830],
            ['Asata', 'Enugu', 'Enugu', true, 6.4505, 7.4915],
            /*
             * The seventh field is a slug, where the name alone will not do.
             * Lagos has a Maryland as well, and slugs are unique across the
             * whole table because they are what a filter link and a saved
             * search carry — whichever Maryland was added first would take the
             * name and the second would be refused.
             */
            ['Maryland', 'Enugu', 'Enugu', true, 6.4255, 7.5115, 'maryland-enugu'],
        ];

        foreach ($areas as $area) {
            [$name, $city, $state, $coverage, $lat, $lng] = $area;

            Area::create([
                'name' => $name,
                'slug' => $area[6] ?? Str::slug($name),
                'city' => $city,
                'state' => $state,
                'is_scan_coverage' => $coverage,
                'centroid_lat' => $lat,
                'centroid_lng' => $lng,
            ]);
        }

        $this->command?->info('  '.count($areas).' areas seeded.');
    }

    /**
     * FR-M5-09.
     *
     * The set that actually drives decisions in this market. A US amenity list
     * — pets allowed, in-unit laundry — would filter on nothing here, while
     * the three that decide a Lagos viewing are power, water and security.
     */
    private function seedAmenities(): void
    {
        if (Amenity::exists()) {
            $this->command?->line('  Amenities already present — left alone.');

            return;
        }

        $amenities = [
            ['24-hour power', 'power'],
            ['Inverter + solar', 'power'],
            ['Generator included', 'power'],
            ['Treated borehole', 'water'],
            ['Water treatment plant', 'water'],
            ['Gated estate', 'security'],
            ['Estate security', 'security'],
            ['CCTV', 'security'],
            ['BQ included', 'space'],
            ['Parking space', 'space'],
            ['Elevator', 'space'],
            ['Swimming pool', 'leisure'],
            ['Gym', 'leisure'],
            ['POP ceiling', 'finish'],
            ['Fitted kitchen', 'finish'],
            ['All rooms ensuite', 'finish'],
            ['Serviced (service charge)', 'finish'],
        ];

        foreach ($amenities as $i => [$name, $group]) {
            Amenity::create([
                'name' => $name,
                'slug' => Str::slug($name),
                'group' => $group,
                'sort_order' => $i,
            ]);
        }

        $this->command?->info('  '.count($amenities).' amenities seeded.');
    }
}
