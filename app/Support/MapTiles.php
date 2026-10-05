<?php

namespace App\Support;

/**
 * Where the map tiles come from (FR-M5-02).
 *
 * Three maps now draw from this — search, a listing, and the pin picker on the
 * listing form — and each was reading the same three config keys and building
 * the same payload. One place to read them is worth having on its own, but the
 * reason this class exists is the thing those three copies could not do:
 * notice that the tiles are wrong.
 *
 * THE DEFAULT IS NOT A PRODUCTION TILE SOURCE. It points at OpenStreetMap's own
 * raster service, whose usage policy prohibits heavy or commercial use. A
 * property marketplace is both. They are entitled to block the traffic, and
 * when they do, every map on the site turns grey at once with nothing in the
 * logs to say why — the failure arrives from outside, on their schedule, not
 * on a deploy.
 *
 * Nothing here throws. A map that cannot draw is a visibly broken map, which
 * announces itself; taking the whole site down over a tile URL would be a
 * worse outcome than the problem. Instead the misconfigurations that are
 * invisible until a visitor hits them are reported — to the admin dashboard,
 * where an operator looks, and to anyone running the deployment checklist.
 */
final class MapTiles
{
    /** The service the config ships pointing at, which is for development. */
    private const PUBLIC_OSM = 'tile.openstreetmap.org';

    /**
     * A URL copied out of a provider's dashboard with its placeholder left in
     * it. Every one of these renders a grey map and a 403 per tile.
     */
    private const UNFILLED = '/\{\s*(api[_-]?key|apikey|key|token|access[_-]?token)\s*\}|YOUR[_-]?(API[_-]?)?KEY/i';

    /*
     * Which source won is settled in config/agentpro.php — MapTiler when there
     * is a key, a hand-set URL over everything, the development source when
     * there is neither — because env() only answers while a config file is
     * being read. After `config:cache`, which every deploy runs, it returns
     * null anywhere else. Reading it here would work in development and
     * quietly stop working in production.
     */
    public static function url(): string
    {
        return (string) config('agentpro.map.tile_url');
    }

    public static function attribution(): string
    {
        return (string) config('agentpro.map.attribution');
    }

    /** The key, or null when there is nothing usable configured. */
    private static function mapTilerKey(): ?string
    {
        $key = trim((string) config('agentpro.map.maptiler_key'));

        return $key === '' ? null : $key;
    }

    public static function maxZoom(): int
    {
        return (int) config('agentpro.map.max_zoom');
    }

    /**
     * The payload every map on the site hands its script, plus whatever that
     * particular map needs on top.
     *
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    public static function forView(array $extra = []): array
    {
        $tileSize = (int) config('agentpro.map.tile_size');

        return array_merge([
            'tileUrl'     => self::url(),
            'attribution' => self::attribution(),
            'maxZoom'     => self::maxZoom(),
            'tileSize'    => $tileSize,
            // Leaflet counts zoom levels in 256px tiles. A provider serving
            // 512s covers the same ground in one fewer level, and without the
            // offset every map opens one level too far in.
            'zoomOffset'  => $tileSize > 256 ? -1 : 0,
        ], $extra);
    }

    public static function isPublicOsm(): bool
    {
        return str_contains(self::url(), self::PUBLIC_OSM);
    }

    /**
     * What is wrong with the tile configuration, in words an operator can act
     * on. Empty when there is nothing to say.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $problems = [];
        $url      = self::url();

        /*
         * Only in production. In development OpenStreetMap's service is exactly
         * the right thing to use — it needs no account, which is what makes a
         * clone of this repository run with working maps out of the box.
         */
        if (self::isPublicOsm() && app()->environment('production')) {
            $problems[] = 'Maps are drawing from OpenStreetMap’s public tile service, which its usage '
                .'policy does not allow for commercial use. Set AGENTPRO_TILE_URL and '
                .'AGENTPRO_TILE_ATTRIBUTION to a provider you have an account with.';
        }

        /*
         * Narrow on purpose. A MapTiler key is a random alphanumeric string,
         * so anything that starts with "your" or carries a space is somebody's
         * placeholder — while a prefix match on "example" or "key" would
         * eventually reject a real one and send its owner looking for a fault
         * that is not there.
         */
        if (($key = self::mapTilerKey()) !== null
            && (preg_match('/^your/i', $key) || preg_match('/[\s<>]/', $key)
                || in_array(strtolower($key), ['key', 'xxx', 'example', 'changeme', 'placeholder'], true))) {
            $problems[] = 'AGENTPRO_MAPTILER_KEY still looks like the example rather than a key, so '
                .'MapTiler is refusing every tile.';
        }

        if ($url === '') {
            $problems[] = 'AGENTPRO_TILE_URL is empty, so no map on the site can draw anything.';
        } elseif (preg_match(self::UNFILLED, $url)) {
            $problems[] = 'The tile URL still has the provider’s placeholder in it where the API key '
                .'belongs, so every tile request is being refused.';
        }

        /*
         * Attribution is not decoration. Every provider worth paying requires
         * the credit as a term of the licence, and a URL swapped in .env
         * without the matching attribution leaves the previous provider's name
         * under the new provider's tiles.
         */
        if (! self::isPublicOsm() && trim(strip_tags(self::attribution())) === '') {
            $problems[] = 'The map has no attribution. Tile providers require the credit as a condition '
                .'of use — set AGENTPRO_TILE_ATTRIBUTION to the line your provider asks for.';
        }

        return $problems;
    }
}
