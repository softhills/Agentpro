<?php

namespace App\Support;

use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * What this visitor has agreed to (FR-M1-02, FR-M13-04).
 *
 * The requirement is that the banner "defaults to essential-only" and that no
 * non-essential tracking happens before consent is given. The difficult part is
 * not the banner — it is deciding what "tracking" means, because a reading that
 * is too broad makes the lister's analytics permanently empty and a reading
 * that is too narrow makes the banner a lie.
 *
 * The line drawn here is between COUNTING and FOLLOWING.
 *
 *   Counting that an event happened, with nothing attached that could identify
 *   who it happened to, produces a number. A number is not personal data, there
 *   is no data subject in it, and nobody can be re-identified from it. This
 *   needs no consent and happens for everyone — which is what keeps "your
 *   listing was viewed 340 times" true rather than "340 times by the minority
 *   who accepted cookies", a figure no lister could act on.
 *
 *   Following one person across requests — stitching a search to a detail view
 *   to a contact, or timing how long they stayed — requires an identifier that
 *   persists between requests. That is tracking on any honest reading, and it
 *   happens only after an explicit yes.
 *
 * So consent does not switch analytics on and off. It switches the visitor id
 * on and off, and the things that need one degrade accordingly: totals survive,
 * journeys do not. Every report that depends on journeys states what share of
 * traffic it could see, so a conversion rate measured over consenting visitors
 * alone is never presented as a conversion rate.
 *
 * The session cookie is untouched by any of this. It carries the CSRF token and
 * the login, it is strictly necessary for the site to work, and it is what the
 * "essential" in essential-only means.
 */
final class Consent
{
    public const COOKIE = 'agentpro_consent';

    public const VISITOR_COOKIE = 'agentpro_visitor';

    /** Essential only — the default, and what an unanswered banner means. */
    public const ESSENTIAL = 'essential';

    /** Essential plus analytics. Nothing else: there is no advertising tier. */
    public const ANALYTICS = 'analytics';

    /**
     * A year. Long enough that nobody is asked twice a week, short enough that
     * a decision made once does not bind somebody forever.
     */
    private const REMEMBER_DAYS = 365;

    /**
     * Has this visitor made a choice at all?
     *
     * Reads the raw cookie rather than going through current(), which resolves
     * an absent value to "essential" and so can never report that nobody has
     * answered. "Not asked yet" and "asked and declined" are the same state for
     * every purpose except one — whether to show the banner — and that one is
     * this method's only job.
     */
    public static function answered(): bool
    {
        return in_array(
            (string) Request::cookie(self::COOKIE, ''),
            [self::ESSENTIAL, self::ANALYTICS],
            true
        );
    }

    /** What they chose, defaulting to essential-only when they have not. */
    public static function current(): string
    {
        $value = (string) Request::cookie(self::COOKIE, '');

        return $value === self::ANALYTICS ? self::ANALYTICS : self::ESSENTIAL;
    }

    /** May this visitor be followed between requests? */
    public static function allowsAnalytics(): bool
    {
        return self::current() === self::ANALYTICS;
    }

    /**
     * The stitching key, or null.
     *
     * Random and meaningless — not derived from an IP address, a user agent or
     * anything else about the person, because a derived id would still identify
     * them after the cookie was cleared, which is the thing clearing it is
     * supposed to prevent.
     */
    public static function visitorId(): ?string
    {
        if (! self::allowsAnalytics()) {
            return null;
        }

        $existing = (string) Request::cookie(self::VISITOR_COOKIE, '');

        if (preg_match('/^[0-9a-f]{32}$/', $existing) === 1) {
            return $existing;
        }

        return null;
    }

    /**
     * Record a decision, returning the cookies to send with the response.
     *
     * Declining does not merely stop writing the visitor id — it actively
     * clears the cookie holding it. An identifier that survives somebody
     * turning tracking off is the exact thing the setting promises to remove,
     * and leaving it in place would make the banner dishonest.
     *
     * @return list<\Symfony\Component\HttpFoundation\Cookie>
     */
    public static function decide(string $choice): array
    {
        $choice = $choice === self::ANALYTICS ? self::ANALYTICS : self::ESSENTIAL;

        $minutes = self::REMEMBER_DAYS * 24 * 60;

        // Not httpOnly: the banner script has to be able to tell whether it
        // should render at all, and the value is a preference rather than a
        // credential. SameSite=Lax so it survives a normal navigation in from
        // a search engine without riding along on cross-site requests.
        $cookies = [Cookie::make(self::COOKIE, $choice, $minutes, null, null, null, false, false, 'lax')];

        $cookies[] = $choice === self::ANALYTICS
            ? Cookie::make(self::VISITOR_COOKIE, self::freshVisitorId(), $minutes, null, null, null, true, false, 'lax')
            : Cookie::forget(self::VISITOR_COOKIE);

        return $cookies;
    }

    /** Keep an existing id across a re-consent rather than fragmenting one visitor into two. */
    private static function freshVisitorId(): string
    {
        $existing = (string) Request::cookie(self::VISITOR_COOKIE, '');

        return preg_match('/^[0-9a-f]{32}$/', $existing) === 1
            ? $existing
            : bin2hex(random_bytes(16));
    }

    /**
     * A crude, deliberately conservative crawler check.
     *
     * Not a security control — anything can claim to be a browser — but the
     * failure it prevents is real and unglamorous: a lister opening their
     * dashboard to "1,400 views" that were all Googlebot, concluding the
     * numbers are nonsense, and never looking again. A view count is only worth
     * having if it is believable.
     */
    public static function looksLikeABot(?string $userAgent): bool
    {
        if ($userAgent === null || trim($userAgent) === '') {
            // Real browsers send one. Something that does not is automated,
            // and counting it costs more in credibility than dropping it does
            // in completeness.
            return true;
        }

        return Str::contains(Str::lower($userAgent), [
            'bot', 'crawl', 'spider', 'slurp', 'curl', 'wget', 'python',
            'headless', 'phantom', 'lighthouse', 'preview', 'monitor',
            'facebookexternalhit', 'whatsapp', 'telegram', 'embedly',
        ]);
    }
}
