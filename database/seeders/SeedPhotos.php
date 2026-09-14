<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Real photographs for the development inventory.
 *
 * The map-first search looks broken on an empty city (PRD risk R9), and it
 * looks nearly as unconvincing on a city of grey placeholder rectangles — you
 * cannot tell whether the media pipeline works, whether the cards are the right
 * shape, or whether a gallery of nine photographs is too many. So the seed
 * fetches real ones.
 *
 * SOURCE AND LICENCE. These are Unsplash photographs, used under the Unsplash
 * Licence, which permits free use including commercially and requires no
 * permission. They are development fixtures, not stock the business has bought:
 * a production deployment carries photographs its listers uploaded, and nothing
 * here ships to one.
 *
 * WHY IDS AND NOT A RANDOM ENDPOINT. Each listing gets the same photograph
 * every time the seed runs. A random image service would make every demo look
 * different from the last one and every screenshot impossible to compare, and
 * it would turn "is this listing rendering correctly" into a question nobody
 * could answer twice the same way.
 *
 * WHY IT MAY RETURN NOTHING. The download is cached under storage/app and the
 * whole thing degrades to the existing placeholder artwork when the network is
 * unavailable. A seeder that fails on a train is a seeder people stop running,
 * and the placeholder was built for exactly this.
 */
class SeedPhotos
{
    /**
     * Interiors, for apartments and flats.
     *
     * @var list<string>
     */
    private const INTERIORS = [
        '1560448204-e02f11c3d0e2', // living room, bay window
        '1560185007-cde436f6a4d0', // dining room
        '1502672260266-1c1ef2d93688', // living room with plants
        '1493809842364-78817add7ffb', // sitting room, blue sofa
        '1484154218962-a197022b5858', // fitted kitchen
        '1522708323590-d24dbb6b0267', // open-plan living
        '1556911220-bff31c812dba', // kitchen worktop
        '1600607687939-ce8a6c25118c', // open-plan living and kitchen
        '1600566753086-00f18fb6b3ea', // living room with staircase
        '1600210492486-724fe5c67fb0', // double-height living room
    ];

    /**
     * Exteriors, for houses and duplexes.
     *
     * @var list<string>
     */
    private const EXTERIORS = [
        '1512917774080-9991f1c4c750', // modern house, pool
        '1600585154340-be6161a56a0c', // modern house among trees
        '1564013799919-ab600027ffc6', // white house with pool
        '1600596542815-ffad4c1539a9', // contemporary frontage
    ];

    /**
     * Land.
     *
     * Two only, and deliberately so: an open plot and an aerial of a developed
     * estate are the two photographs a Nigerian land listing actually carries.
     * Scenery — mountains, lakes — was rejected for these, because a plot in
     * Ibeju-Lekki does not look like a Swiss valley and dressing it up as one
     * would be the exact overselling this platform exists to stop.
     *
     * @var list<string>
     */
    private const LAND = [
        '1500382017468-9049fed747ef', // open ground at first light
        '1516156008625-3a9d6067fab5', // aerial, developed estate
    ];

    /** Where the fetched originals are cached between runs. */
    private const CACHE = 'seed-photos';

    private bool $offline = false;

    /**
     * How many listings of each type have been served, so covers rotate
     * through a pool one at a time.
     *
     * The caller used to pass an offset derived from the property id, and it
     * collided: a stride of three against a pool of four exteriors put the
     * first and third house on the same photograph. Counting here removes the
     * arithmetic from the caller and guarantees distinct covers until the pool
     * is genuinely exhausted, which is the only honest reason to repeat one.
     *
     * @var array<string,int>
     */
    private array $served = [];

    /**
     * Local paths for one listing's photographs, cover first.
     *
     * Repeats deeper in a gallery are unavoidable with a pool this size, and
     * harmless: duplicate detection only compares listings within 120 metres of
     * each other, and the seed spreads these across ten areas in two cities.
     *
     * @return list<string>
     */
    public function forListing(string $listingType, int $count): array
    {
        $offset = $this->served[$listingType] = ($this->served[$listingType] ?? -1) + 1;

        /*
         * The cover comes from a pool the listing type actually leads with — a
         * house shows the building, a flat shows the room you would sit in —
         * and the rest of the gallery mixes.
         *
         * It also fixes a collision. A single pool per type held the same
         * photographs in a different order, so a rotating offset could land two
         * listings of different types on the same cover: an apartment in Ikate
         * and a house in Maitama fronted by one photograph, which on a platform
         * whose whole claim is that listings are real is the worst possible
         * thing for the seed to demonstrate.
         */
        [$coverPool, $restPool] = match ($listingType) {
            'land'  => [self::LAND, self::LAND],
            'house' => [self::EXTERIORS, array_merge(self::INTERIORS, self::EXTERIORS)],
            default => [self::INTERIORS, array_merge(self::INTERIORS, self::EXTERIORS)],
        };

        $ids = [$coverPool[$offset % count($coverPool)]];

        for ($i = 1; $i < $count; $i++) {
            $ids[] = $restPool[($offset + $i) % count($restPool)];
        }

        $paths = [];

        foreach ($ids as $id) {
            if ($path = $this->fetch($id)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** True once a download has failed, so the rest of the seed stops trying. */
    public function isOffline(): bool
    {
        return $this->offline;
    }

    /**
     * The cached original, downloading it once if this is the first run.
     *
     * 1600px wide: enough for the largest rendition the processor writes, and
     * not so large that a first seed pulls thirty megabytes.
     */
    private function fetch(string $id): ?string
    {
        if ($this->offline) {
            return null;
        }

        $dir = storage_path('app/'.self::CACHE);
        $file = $dir.'/'.$id.'.jpg';

        if (is_file($file) && filesize($file) > 0) {
            return $file;
        }

        File::ensureDirectoryExists($dir);

        try {
            $response = Http::timeout(20)->retry(2, 500)
                ->get('https://images.unsplash.com/photo-'.$id, ['w' => 1600, 'q' => 80, 'fm' => 'jpg']);

            if (! $response->successful() || strlen($response->body()) < 1024) {
                $this->offline = true;

                return null;
            }

            File::put($file, $response->body());

            return $file;
        } catch (\Throwable $e) {
            // One failure is enough to conclude there is no network. Trying
            // again forty more times just makes the seed take four minutes to
            // arrive at the same answer.
            $this->offline = true;

            return null;
        }
    }
}
