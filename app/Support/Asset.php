<?php

namespace App\Support;

/**
 * Cache-busted URLs for the files this application ships itself.
 *
 * There is no build step here — the CSS is hand-written and served straight out
 * of `public/` (PRD: PHP and MySQL, server-rendered, cheap to deploy) — so
 * nothing fingerprints these filenames the way a bundler would. Without that,
 * `css/app.css` is a URL whose contents change and whose name does not, and
 * every browser that has ever loaded the site keeps serving its copy.
 *
 * That is not a theoretical problem: a deployment went out, the server was
 * verified to be serving the new file, and the change was still invisible in
 * the browser. A visitor cannot be asked to hard-refresh, and on a marketplace
 * they will not come back to find out whether it looks better today.
 *
 * The version is the file's modification time. A deploy rewrites the file, the
 * timestamp moves, the URL changes, and every browser fetches once. Nothing to
 * remember and nothing to increment by hand — which matters, because a version
 * constant somebody has to bump is a version constant that stops being bumped.
 */
class Asset
{
    /**
     * Memoised per request. Three or four stat() calls a page is already
     * nothing, but the same file is asked for on every render and the answer
     * cannot change while a request is in flight.
     *
     * @var array<string,string>
     */
    private static array $versions = [];

    public static function url(string $path): string
    {
        return asset($path).'?v='.self::version($path);
    }

    private static function version(string $path): string
    {
        if (isset(self::$versions[$path])) {
            return self::$versions[$path];
        }

        $mtime = @filemtime(public_path($path));

        /*
         * A missing file falls back to the application version rather than
         * throwing. The asset is already broken at that point; turning a
         * missing stylesheet into a 500 on every page of the site makes a
         * visible problem into a total outage.
         */
        return self::$versions[$path] = (string) ($mtime ?: config('agentpro.version', '1'));
    }
}
