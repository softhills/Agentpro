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
     * The ten coverage areas from the spec, plus nearby areas deliberately
     * outside it — the 3D upgrade has to be seen to refuse somewhere.
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
            // Outside coverage
            ['Ajah', 'Lagos', 'Lagos', false, 6.4698, 3.5852],
            ['Gbagada', 'Lagos', 'Lagos', false, 6.5568, 3.3903],
            ['Gwarinpa', 'Abuja', 'FCT', false, 9.1090, 7.4030],
        ];

        foreach ($areas as [$name, $city, $state, $coverage, $lat, $lng]) {
            Area::create([
                'name' => $name,
                'slug' => Str::slug($name),
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
