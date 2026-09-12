<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Queries\AdminMetrics;

class DashboardController extends Controller
{
    public function __invoke(AdminMetrics $metrics)
    {
        return view('admin.dashboard', [
            'trust'      => $metrics->trust(),
            'immersive'  => $metrics->immersive(),
            'fees'       => $metrics->feeTransparency(),
            'supply'     => $metrics->supply(),
            'moderation' => $metrics->moderation(),
            'revenue'    => $metrics->revenue(),
            'operations' => $metrics->operations(),
            'integrity'  => $metrics->integrity(),
            'engagement' => $metrics->engagement(),
            'activity'   => $metrics->recentActivity(),
        ]);
    }
}
