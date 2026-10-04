<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enugu's areas, for a database that was seeded when the site covered two
 * cities.
 *
 * ReferenceDataSeeder now carries Enugu, but it seeds only an empty table —
 * deliberately, because neither areas nor amenities soft-delete and a seeder
 * that wrote on every deploy would resurrect whatever an administrator had
 * removed. That guard is right and it means an install already carrying the
 * Lagos and Abuja set will never see Enugu, however many times the seeder is
 * run. A migration is the one thing that happens exactly once per database,
 * and is recorded as having happened, which is what adding a city to an
 * existing install needs.
 *
 * The areas arrive closed for 3D capture. Coverage commits the field team to
 * servicing an area and is opened on the coverage screen by Operations
 * (FR-M4-02) — the same rule that stops the console opening an area as a side
 * effect of naming one.
 */
return new class extends Migration
{
    /** @var list<array{string,string,float,float}> name, slug, lat, lng */
    private const AREAS = [
        ['Independence Layout', 'independence-layout', 6.4335, 7.5160],
        ['Enugu GRA', 'enugu-gra', 6.4453, 7.4968],
        ['New Haven', 'new-haven', 6.4512, 7.4820],
        ['Ogui New Layout', 'ogui-new-layout', 6.4423, 7.4890],
        ['Achara Layout', 'achara-layout', 6.4310, 7.4760],
        ['Trans-Ekulu', 'trans-ekulu', 6.4722, 7.5201],
        ['Abakpa Nike', 'abakpa-nike', 6.4790, 7.5370],
        ['Thinkers Corner', 'thinkers-corner', 6.4600, 7.5500],
    ];

    public function up(): void
    {
        /*
         * A new install reaches this migration with an empty areas table, and
         * that table belongs to ReferenceDataSeeder, which runs afterwards and
         * seeds all three cities in one go. Writing here would leave the table
         * non-empty, the seeder would find it populated and skip itself, and
         * the install would come up with Enugu and no Lagos or Abuja at all.
         */
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
            // Somebody may have added one by hand on Amenities & areas already.
            // Theirs stays as they typed it, name, centroid and coverage flag.
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
        /*
         * Only while nothing points at them. properties.area_id is
         * nullOnDelete, so removing an area that has listings does not remove
         * the listings — it detaches them, and they stay published and
         * findable by every filter except the area they are in. That is worse
         * than either extreme, because nothing looks broken.
         *
         * On an install that got these from the seeder rather than from here,
         * this removes them too. Rolling a city back out is approximate by
         * nature; the guard below is what keeps it from costing anything.
         */
        DB::table('areas')
            ->whereIn('slug', array_column(self::AREAS, 1))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('properties')
                ->whereColumn('properties.area_id', 'areas.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('technician_slots')
                ->whereColumn('technician_slots.area_id', 'areas.id'))
            ->delete();
    }
};
