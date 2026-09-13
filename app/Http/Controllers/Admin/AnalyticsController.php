<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Queries\Funnel;

/**
 * Internal reporting on the two funnels (FR-M13-02, FR-M13-03).
 *
 * A screen of its own rather than another band on the admin dashboard. That
 * dashboard answers "is the business working" against the objectives in PRD §2
 * and is already dense; this answers "where do people fall out", which is a
 * different question asked at a different moment, and stapling one onto the
 * other would make both harder to read.
 */
class AnalyticsController extends Controller
{
    public function __invoke()
    {
        $funnel = new Funnel((int) config('agentpro.analytics.window_days'));

        return view('admin.analytics', [
            'seeker' => $funnel->seeker(),
            'lister' => $funnel->lister(),
            'dwell'  => $funnel->tourDwell(),
            'areas'  => $funnel->coldSpots(),
        ]);
    }
}
