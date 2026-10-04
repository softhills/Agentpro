<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Three more of Enugu's residential areas, on the same terms as the eight in
 * add_enugu_areas: closed for 3D capture, which Operations opens on the
 * coverage screen when the field team can service them (FR-M4-02).
 *
 * A second migration rather than three more lines in the first one, because
 * the first may already have run. A migration that has been recorded as run is
 * history: editing it changes what a fresh database gets and silently gives
 * nothing to every database that already has it, which is the quiet kind of
 * drift that ends with two installs holding different reference data and
 * nothing to say so.
 */
return new class extends Migration
{
    /** @var list<array{string,string,float,float}> name, slug, lat, lng */
    private const AREAS = [
        ['Uwani', 'uwani', 6.4365, 7.4830],
        ['Asata', 'asata', 6.4505, 7.4915],
        /*
         * Lagos has a Maryland too, and a slug is unique across the whole
         * table because it is what a filter link and a saved search carry.
         * Whichever was added first would take "maryland" and the second would
         * be refused, so Enugu's is qualified from the start.
         */
        ['Maryland', 'maryland-enugu', 6.4255, 7.5115],
    ];

    public function up(): void
    {
        // An empty table belongs to ReferenceDataSeeder, which runs after the
        // migrations and seeds every city in one go — and only ever into an
        // empty table. Writing here would leave it non-empty, the seeder would
        // skip itself, and a new install would come up with three Enugu areas
        // and nothing else at all.
        if (DB::table('areas')->count() === 0) {
            return;
        }

        $taken = DB::table('areas')
            ->whereIn('slug', array_column(self::AREAS, 1))
            ->pluck('slug')
            ->all();

        $now  = now();
        $rows = [];

        foreach (self::AREAS as [$name, $slug, $lat, $lng]) {
            // Added by hand on Amenities & areas already: theirs stands.
            if (in_array($slug, $taken, true)) {
                continue;
            }

            $rows[] = [
                'name'             => $name,
                'slug'             => $slug,
                'city'             => 'Enugu',
                'state'            => 'Enugu',
                'is_scan_coverage' => false,
                'centroid_lat'     => $lat,
                'centroid_lng'     => $lng,
                'default_zoom'     => 14,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('areas')->insert($rows);
        }
    }

    public function down(): void
    {
        // Only while nothing points at them: properties.area_id is
        // nullOnDelete, so removing an area that has listings detaches them
        // rather than removing them, and they stay published and findable by
        // every filter except the area they belong to.
        DB::table('areas')
            ->whereIn('slug', array_column(self::AREAS, 1))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('properties')
                ->whereColumn('properties.area_id', 'areas.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('technician_slots')
                ->whereColumn('technician_slots.area_id', 'areas.id'))
            ->delete();
    }
};
