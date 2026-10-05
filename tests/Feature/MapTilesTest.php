<?php

namespace Tests\Feature;

use App\Support\MapTiles;
use Tests\TestCase;

/**
 * The tile source, and the three ways it goes wrong quietly.
 *
 * Every one of these shows up as grey squares on somebody else's screen — a
 * licence breach the provider enforces when it suits them, a key left as a
 * placeholder, a URL swapped without its attribution. None of them fails on
 * deploy, so none of them is noticed by the person who caused it.
 */
class MapTilesTest extends TestCase
{
    private function tiles(string $url, string $attribution = '', string $env = 'production'): array
    {
        config(['agentpro.map.tile_url' => $url, 'agentpro.map.attribution' => $attribution]);
        app()['env'] = $env;

        return MapTiles::problems();
    }

    public function test_the_development_default_is_a_problem_only_in_production(): void
    {
        $osm = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

        $this->assertNotEmpty(
            $this->tiles($osm, '© OpenStreetMap contributors'),
            'Production on OpenStreetMap’s public tiles went unreported.',
        );

        // A clone of this repository has working maps with no account anywhere,
        // and that is worth keeping — so this must stay quiet in development.
        $this->assertSame([], $this->tiles($osm, '© OpenStreetMap contributors', 'local'));
    }

    public function test_a_configured_provider_is_not_complained_about(): void
    {
        $this->assertSame([], $this->tiles(
            'https://api.someprovider.com/maps/streets/{z}/{x}/{y}.png?key=abc123',
            '© Some Provider © OpenStreetMap contributors',
        ));
    }

    /** The commonest way a tile swap fails: the URL pasted as the docs print it. */
    public function test_a_placeholder_left_where_the_key_belongs_is_caught(): void
    {
        foreach ([
            'https://api.p.com/{z}/{x}/{y}.png?key={key}',
            'https://api.p.com/{z}/{x}/{y}.png?api_key={api_key}',
            'https://api.p.com/{z}/{x}/{y}.png?apikey=YOUR_KEY',
            'https://api.p.com/{z}/{x}/{y}.png?access_token={access_token}',
        ] as $url) {
            $problems = implode(' ', $this->tiles($url, '© Provider'));

            $this->assertStringContainsString('placeholder', $problems, $url.' passed as configured.');
        }
    }

    /** {z}/{x}/{y} and the retina {r} are Leaflet's own, not an unfilled key. */
    public function test_the_tile_coordinates_are_not_mistaken_for_a_missing_key(): void
    {
        $this->assertSame([], $this->tiles(
            'https://tiles.p.com/tiles/osm/{z}/{x}/{y}{r}.png?api_key=real-key-here',
            '© Provider',
        ));
    }

    public function test_tiles_without_the_credit_their_licence_requires_are_caught(): void
    {
        $problems = implode(' ', $this->tiles('https://api.p.com/{z}/{x}/{y}.png?key=abc', ''));

        $this->assertStringContainsString('attribution', $problems);
    }

    public function test_an_empty_setting_falls_back_rather_than_drawing_nothing(): void
    {
        // AGENTPRO_TILE_URL= in .env is a value, not an absence, and the config
        // has to coalesce it or every map on the site renders blank.
        $this->assertSame(
            'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            config('agentpro.map.tile_url'),
        );
    }

    /** One payload, three maps. */
    public function test_a_map_is_handed_the_tiles_and_whatever_else_it_needs(): void
    {
        config(['agentpro.map.tile_url' => 'https://t/{z}/{x}/{y}.png', 'agentpro.map.attribution' => '© P']);

        $this->assertSame([
            'tileUrl'     => 'https://t/{z}/{x}/{y}.png',
            'attribution' => '© P',
            'maxZoom'     => (int) config('agentpro.map.max_zoom'),
            'lat'         => 6.45,
        ], MapTiles::forView(['lat' => 6.45]));
    }
}
