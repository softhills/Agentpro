<?php

namespace App\Http\Controllers;

use App\Enums\LifecycleState;
use App\Models\Area;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * sitemap.xml (FR-M5-08).
 *
 * Listing pages are already server-rendered and carry RealEstateListing
 * structured data, which makes each one indexable on its own. This is the other
 * half: a crawler that has never seen the site has no way to discover a listing
 * that nothing links to, and in a marketplace the long tail is most of it.
 *
 * Sold and rented listings are excluded even though they remain reachable
 * (FR-M2-09). They are retained so a seeker who followed a link still finds
 * something rather than a 404, but submitting them for indexing would fill
 * search results with property nobody can buy — which is the stale-listing
 * problem P1, reintroduced through the back door.
 */
class SitemapController extends Controller
{
    /**
     * An hour.
     *
     * Long enough that a crawler asking repeatedly costs one table scan rather
     * than hundreds; short enough that a listing published this morning is
     * discoverable today. Nothing here is personalised, so one cached copy
     * serves everybody.
     */
    private const TTL = 3600;

    public function __invoke()
    {
        $xml = Cache::remember('sitemap.xml', self::TTL, fn () => $this->build());

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    private function build(): string
    {
        $urls = [];

        // The corporate pages, highest priority because they are what a brand
        // search should land on.
        foreach ([
            ['pages.realsure', '0.9', 'monthly'],
            ['pages.areas',    '0.8', 'weekly'],
            ['pages.agents',   '0.7', 'weekly'],
            // FR-M2-09. Weekly rather than monthly: its whole value is being
            // current, and a crawler holding a three-week-old copy of "what has
            // sold" is showing the opposite of what the page is for.
            ['pages.closed',   '0.6', 'weekly'],
            ['pages.about',    '0.5', 'yearly'],
            ['pages.terms',    '0.3', 'yearly'],
            ['pages.privacy',  '0.3', 'yearly'],
        ] as [$name, $priority, $frequency]) {
            $urls[] = $this->url(route($name), null, $frequency, $priority);
        }

        $urls[] = $this->url(route('home'), null, 'daily', '1.0');
        $urls[] = $this->url(route('search'), null, 'daily', '0.9');

        Area::orderBy('slug')->get()->each(function (Area $area) use (&$urls) {
            $urls[] = $this->url(route('pages.area', $area), $area->updated_at, 'weekly', '0.6');
        });

        /*
         * Chunked rather than loaded whole. At six thousand live listings
         * (objective O4) a get() here would hold the entire table in memory to
         * build a string, on a single VPS, every time the cache expired.
         */
        Property::where('lifecycle_state', LifecycleState::Published->value)
            ->orderBy('id')
            ->chunkById(500, function ($chunk) use (&$urls) {
                foreach ($chunk as $property) {
                    $urls[] = $this->url(
                        route('property.show', $property),
                        $property->content_updated_at ?? $property->published_at,
                        'daily',
                        $property->realsure_verified_at ? '0.8' : '0.7',
                    );
                }
            });

        // Only verified listers with something live, matching the directory.
        // A profile the directory will not show should not be submitted for
        // indexing either.
        User::where('category', '!=', 'seeker')
            ->where('verification_state', 'verified')
            ->whereNull('deleted_at')
            ->whereHas('properties', fn ($q) => $q
                ->where('lifecycle_state', LifecycleState::Published->value))
            ->orderBy('id')
            ->chunkById(500, function ($chunk) use (&$urls) {
                foreach ($chunk as $user) {
                    $urls[] = $this->url(route('pages.agent', $user), $user->updated_at, 'weekly', '0.5');
                }
            });

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .implode('', $urls)
            .'</urlset>'."\n";
    }

    private function url(string $location, $lastModified, string $frequency, string $priority): string
    {
        return '  <url>'."\n"
            .'    <loc>'.htmlspecialchars($location, ENT_XML1).'</loc>'."\n"
            .($lastModified ? '    <lastmod>'.$lastModified->toAtomString().'</lastmod>'."\n" : '')
            .'    <changefreq>'.$frequency.'</changefreq>'."\n"
            .'    <priority>'.$priority.'</priority>'."\n"
            .'  </url>'."\n";
    }
}
