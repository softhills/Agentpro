<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Support\Analytics;
use App\Support\Consent;
use Illuminate\Http\Request;

/**
 * The two things the browser is allowed to tell the server about (M13).
 *
 * Opening a poster-gated embed and then staying with it both happen entirely in
 * the page — the server sees one request for the listing and nothing after it —
 * so objective O2's "median tour dwell time" cannot be measured any other way.
 * That makes this the only untrusted path into the event store, and it is
 * written accordingly: a fixed allowlist of two event names, a bounded value,
 * and a listing that has to exist and be public.
 */
class AnalyticsController extends Controller
{
    /**
     * Record the visitor's cookie decision (FR-M1-02).
     *
     * A POST rather than a script writing the cookie itself, so the choice
     * survives with the same guarantees as every other cookie the application
     * sets, and so declining can actively clear the visitor id rather than
     * merely stopping new ones.
     */
    public function consent(Request $request)
    {
        $data = $request->validate([
            'choice' => ['required', 'in:'.Consent::ESSENTIAL.','.Consent::ANALYTICS],
        ]);

        $response = $request->expectsJson()
            ? response()->json(['choice' => $data['choice']])
            : back();

        foreach (Consent::decide($data['choice']) as $cookie) {
            $response->withCookie($cookie);
        }

        return $response;
    }

    /**
     * Accept a browser-reported event.
     *
     * Returns 204 in every case an honest client could produce, including the
     * ones it refuses. A beacon fired from `visibilitychange` has nowhere to
     * put an error — the page is already going — so distinguishing outcomes in
     * the status code would only ever tell an attacker which names are real.
     */
    public function event(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'in:'.implode(',', Analytics::BEACONABLE)],
            'property' => ['required', 'uuid'],
            /*
             * An hour. Long enough for a genuinely thorough tour of a house,
             * short enough that a tab left open over a weekend does not land a
             * 200,000-second dwell in the median. The cap is applied rather
             * than the reading rejected: somebody really did open the tour.
             */
            'value'    => ['nullable', 'integer', 'min:1', 'max:3600'],
            'context'  => ['nullable', 'string', 'max:32'],
        ]);

        $property = Property::where('uuid', $data['property'])->first();

        // Same reasoning as the listing page itself: a draft must not be
        // confirmable through a side channel (SEC-03).
        if (! $property || ! in_array($property->lifecycle_state->value,
            \App\Enums\LifecycleState::publiclyVisible(), true)) {
            return response()->noContent();
        }

        Analytics::record(
            $data['name'],
            $property,
            value: $data['value'] ?? null,
            context: $data['context'] ?? null,
            // One open per listing per session. Dwell is not de-duplicated:
            // a seeker who opens a tour, leaves and comes back has genuinely
            // spent both stretches of time with it.
            once: $data['name'] === Analytics::TOUR_OPEN ? 'tour:'.$property->id : null,
        );

        return response()->noContent();
    }
}
