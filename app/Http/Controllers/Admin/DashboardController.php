<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Queries\AdminMetrics;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, AdminMetrics $metrics)
    {
        return view('admin.dashboard', [
            'trust'      => $metrics->trust(),
            'immersive'  => $metrics->immersive(),
            'fees'       => $metrics->feeTransparency(),
            'supply'     => $metrics->supply(),
            'moderation' => $metrics->moderation(),
            'revenue'    => $metrics->revenue(),
            // Finance figures only for the people who can act on them: a
            // moderator cannot open the settlements screen, and an alert that
            // leads to a 404 trains people to ignore alerts.
            'money'      => $request->user()->isStaff('admin') ? $metrics->money() : null,
            'operations' => $metrics->operations(),
            'integrity'  => $metrics->integrity(),
            'engagement' => $metrics->engagement(),
            'activity'   => $metrics->recentActivity(),
        ]);
    }
}
