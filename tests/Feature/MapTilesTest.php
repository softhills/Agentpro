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

    /**
     * The config file decides which source wins, so these load it the way the
     * framework does — with the environment set and the file read fresh. That
     * is also the only honest way to test it: after `config:cache` the
     * resolution has already happened, and nothing outside this file can see
     * an environment variable at all.
     *
     * @param  array<string,string>  $env
     * @return array<string,mixed>
     */
    private function mapConfig(array $env): array
    {
        foreach (['AGENTPRO_MAPTILER_KEY', 'AGENTPRO_MAPTILER_STYLE', 'AGENTPRO_MAPTILER_TILE_URL',
                  'AGENTPRO_TILE_URL', 'AGENTPRO_TILE_ATTRIBUTION'] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }

        foreach ($env as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv($name.'='.$value);
        }

        return (require config_path('agentpro.php'))['map'];
    }

    public function test_a_maptiler_key_is_all_it_takes_to_switch_provider(): void
    {
        $map = $this->mapConfig(['AGENTPRO_MAPTILER_KEY' => 'ab12cd34']);

        $this->assertSame(
            'https://api.maptiler.com/maps/streets-v2/256/{z}/{x}/{y}.png?key=ab12cd34',
            $map['tile_url'],
        );

        // The licence asks for the credit, so it arrives with the tiles rather
        // than waiting for somebody to remember a second setting.
        $this->assertStringContainsString('MapTiler', $map['attribution']);
        $this->assertStringContainsString('maptiler.com/copyright', $map['attribution']);
        $this->assertStringContainsString('OpenStreetMap', $map['attribution']);
    }

    public function test_the_style_and_the_whole_url_can_still_be_overridden(): void
    {
        $this->assertStringContainsString('hybrid', $this->mapConfig([
            'AGENTPRO_MAPTILER_KEY' => 'k', 'AGENTPRO_MAPTILER_STYLE' => 'hybrid',
        ])['tile_url']);

        // A provider's tile path is theirs to change; when it does, the fix is
        // a line in .env rather than a deploy of the config file.
        $this->assertSame(
            'https://api.maptiler.com/maps/basic/{z}/{x}/{y}@2x.png?key=k',
            $this->mapConfig([
                'AGENTPRO_MAPTILER_KEY' => 'k',
                'AGENTPRO_MAPTILER_TILE_URL' => 'https://api.maptiler.com/maps/{style}/{z}/{x}/{y}@2x.png?key={key}',
                'AGENTPRO_MAPTILER_STYLE' => 'basic',
            ])['tile_url'],
        );
    }

    /** For a provider this does not know about, or a self-hosted one. */
    public function test_a_url_set_by_hand_beats_maptiler(): void
    {
        $map = $this->mapConfig([
            'AGENTPRO_MAPTILER_KEY' => 'k',
            'AGENTPRO_TILE_URL' => 'https://tiles.mine.example/{z}/{x}/{y}.png',
        ]);

        $this->assertSame('https://tiles.mine.example/{z}/{x}/{y}.png', $map['tile_url']);
        $this->assertStringNotContainsString('MapTiler', $map['attribution'], 'Credited MapTiler for somebody else’s tiles.');
    }

    public function test_without_a_key_it_is_still_the_development_source(): void
    {
        $map = $this->mapConfig([]);

        $this->assertStringContainsString('tile.openstreetmap.org', $map['tile_url']);
        $this->assertStringNotContainsString('MapTiler', $map['attribution']);
    }

    public function test_a_key_left_as_the_example_is_caught(): void
    {
        foreach (['YOUR_KEY', 'your-key-from-the-maptiler-dashboard', 'changeme', 'paste key here'] as $placeholder) {
            config(['agentpro.map.maptiler_key' => $placeholder]);

            $this->assertStringContainsString(
                'looks like the example',
                implode(' ', MapTiles::problems()),
                $placeholder.' was accepted as a key.',
            );
        }
    }

    /**
     * And a real one is left alone. A key is a random alphanumeric string, so
     * a looser check would eventually reject a working one and send its owner
     * hunting a fault that is not there.
     */
    public function test_a_real_looking_key_is_not_second_guessed(): void
    {
        foreach (['gH3kPq9ZxVn2LmT8', 'exampleKeyLooking1', 'keyBut2Random3'] as $key) {
            config([
                'agentpro.map.maptiler_key' => $key,
                'agentpro.map.tile_url' => 'https://api.maptiler.com/maps/streets-v2/256/{z}/{x}/{y}.png?key='.$key,
                'agentpro.map.attribution' => '© MapTiler',
            ]);

            $this->assertSame([], MapTiles::problems(), $key.' was rejected as a placeholder.');
        }
    }

    /** Leaflet counts zoom in 256px tiles; a 512 provider is a level out. */
    public function test_a_larger_tile_carries_the_zoom_offset_with_it(): void
    {
        config(['agentpro.map.tile_size' => 256]);
        $this->assertSame(0, MapTiles::forView()['zoomOffset']);

        config(['agentpro.map.tile_size' => 512]);
        $this->assertSame(-1, MapTiles::forView()['zoomOffset']);
        $this->assertSame(512, MapTiles::forView()['tileSize']);
    }

    /** One payload, three maps. */
    public function test_a_map_is_handed_the_tiles_and_whatever_else_it_needs(): void
    {
        config(['agentpro.map.tile_url' => 'https://t/{z}/{x}/{y}.png', 'agentpro.map.attribution' => '© P']);

        config(['agentpro.map.tile_size' => 256]);

        $this->assertSame([
            'tileUrl'     => 'https://t/{z}/{x}/{y}.png',
            'attribution' => '© P',
            'maxZoom'     => (int) config('agentpro.map.max_zoom'),
            'tileSize'    => 256,
            'zoomOffset'  => 0,
            'lat'         => 6.45,
        ], MapTiles::forView(['lat' => 6.45]));
    }
}
