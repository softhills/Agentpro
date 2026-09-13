<?php

namespace App\Support;

use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;

/**
 * Recording what happened (FR-M13-01, FR-M13-02).
 *
 * Three rules, and the first outranks the other two.
 *
 * IT NEVER BREAKS A PAGE. Every call is wrapped, and a failure here is logged
 * and swallowed. A listing that 500s because the analytics insert deadlocked
 * would be the worst possible trade: the measurement destroying the thing it
 * was measuring. Nothing in this class is worth one failed page view.
 *
 * IT COUNTS PEOPLE ONCE. A seeker who reloads a listing eleven times while
 * deciding has viewed it once, and a view count that says otherwise is worse
 * than none — a lister cannot tell real interest from their own refreshing.
 * De-duplication uses the session, which already exists for the CSRF token, so
 * it introduces no identifier that was not already there and needs no consent.
 *
 * IT COLLECTS AS LITTLE AS IT CAN. No IP address, no user agent, no user id.
 * The visitor id is written only where @see Consent allows one. Everything this
 * table does not hold is something that cannot leak later.
 */
final class Analytics
{
    /**
     * Events the server may record.
     *
     * The browser can only ever send the subset in BEACONABLE below. This wider
     * list is for code paths that are already trusted because they run on the
     * server as a side effect of something real happening.
     */
    public const SEARCH  = 'search';
    public const RESULTS = 'results';
    public const DETAIL  = 'detail';
    public const TOUR_OPEN  = 'tour_open';
    public const TOUR_DWELL = 'tour_dwell';

    /*
     * There is no `save` event, and that is deliberate.
     *
     * A save is already a row in `interactions` with a user and a timestamp,
     * and it is exact: the table is unique on (user, property, kind), so it
     * holds the standing state rather than a stream of occurrences. Recording
     * it here as well would create a second, less accurate version of a number
     * the platform already knows, and the two would drift the first time one
     * path forgot to write the other. Saves are read from `interactions`.
     *
     * Contact is the exception, and for a specific reason: that same uniqueness
     * means a seeker who rings and then messages on WhatsApp overwrites their
     * own row, so `interactions` ends up holding the latest mode per person
     * rather than a count of initiations. FR-M13-01 asks for "contact
     * initiations by mode", which is the thing that row cannot answer.
     */
    public const CONTACT = 'contact';

    /**
     * What a browser is allowed to report.
     *
     * Deliberately tiny. These two cannot be observed server-side — opening a
     * poster-gated embed and then staying with it are both things that happen
     * entirely in the page — so they have to be reported by the client, which
     * makes this the one untrusted way into the event store. Anything not on
     * this list is refused rather than stored, because an endpoint that accepts
     * whatever name it is given is an endpoint that fills the table with
     * whatever somebody feels like putting there.
     *
     * @var list<string>
     */
    public const BEACONABLE = [self::TOUR_OPEN, self::TOUR_DWELL];

    /** Session key holding what this visitor has already been counted for. */
    private const SEEN = 'analytics.seen';

    /**
     * How many de-duplication keys to keep in the session.
     *
     * The session travels in a cookie, so this list cannot grow without bound.
     * Forty listings is more than a browsing session realistically covers, and
     * the cost of falling off the end is one listing counted twice in a very
     * long sitting — an acceptable trade against an ever-growing cookie.
     */
    private const SEEN_LIMIT = 40;

    /**
     * Record an event.
     *
     * `$once` is the de-duplication key. Passing one means "count this at most
     * once per session"; passing null means every occurrence counts, which is
     * right for a search (running two searches is two searches) and wrong for a
     * listing view.
     */
    public static function record(
        string $name,
        Property|int|null $property = null,
        ?int $value = null,
        ?string $context = null,
        ?string $once = null,
    ): bool {
        try {
            if (Consent::looksLikeABot(Request::userAgent())) {
                return false;
            }

            if ($once !== null && ! self::firstTimeThisSession($once)) {
                return false;
            }

            DB::table('analytics_events')->insert([
                'name'        => $name,
                'property_id' => $property instanceof Property ? $property->id : $property,
                'visitor_id'  => Consent::visitorId(),
                'value'       => $value,
                'context'     => $context === null ? null : mb_substr($context, 0, 32),
                'occurred_at' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            /*
             * Swallowed on purpose. Analytics is the least important thing on
             * any page it appears on, and the alternative — a seeker seeing an
             * error because a counter could not be written — is indefensible.
             * It is logged rather than ignored so that a table filling up or an
             * index gone missing is still discoverable.
             */
            Log::warning('Analytics event dropped: '.$e->getMessage(), ['event' => $name]);

            return false;
        }
    }

    /**
     * A listing detail view.
     *
     * Kept together with the denormalised counter on `properties`, because the
     * two must not be able to disagree: the counter is what search ordering and
     * the dashboard read, the event is what the per-day chart reads, and a view
     * that landed in one but not the other would show up as a listing whose
     * total and whose graph tell different stories.
     */
    public static function listingViewed(Property $property): void
    {
        if (! self::record(self::DETAIL, $property, once: 'detail:'.$property->id)) {
            return;
        }

        try {
            // An atomic increment rather than read-modify-write: two people
            // opening the same listing in the same second is the normal case on
            // a popular one, and it is exactly when a lost update happens.
            DB::table('properties')->where('id', $property->id)->increment('view_count');
        } catch (\Throwable $e) {
            Log::warning('View counter not incremented: '.$e->getMessage());
        }
    }

    /**
     * Has this key been seen already in this session?
     *
     * Writes the key as a side effect, so a caller cannot check without
     * claiming — two calls in the same request would otherwise both be "first".
     */
    private static function firstTimeThisSession(string $key): bool
    {
        if (! Session::isStarted()) {
            // No session means no way to tell a reload from a fresh visit. A
            // console command or an API call with no cookies lands here; count
            // it rather than silently dropping it.
            return true;
        }

        $seen = (array) Session::get(self::SEEN, []);

        if (in_array($key, $seen, true)) {
            return false;
        }

        $seen[] = $key;

        Session::put(self::SEEN, array_slice($seen, -self::SEEN_LIMIT));

        return true;
    }
}
