<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\ScanJob;
use App\Models\TechnicianSlot;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Coverage areas and capture capacity (FR-M12-04).
 *
 * Both are the kind of thing Operations changes weekly, which is exactly why
 * they are data rather than configuration in code: opening Gbagada for 3D
 * capture should not require a deployment.
 */
class OperationsController extends Controller
{
    public function index()
    {
        $areas = Area::withCount([
            'properties as live_count' => fn ($q) => $q->where('lifecycle_state', 'published'),
        ])->orderBy('city')->orderBy('name')->get();

        // Open capacity per area, so the constraint on the only paid feature in
        // R1 is visible rather than inferred (risk R2).
        $capacity = DB::table('technician_slots')
            ->selectRaw('area_id, SUM(capacity - booked) AS open, COUNT(*) AS total')
            ->whereDate('slot_date', '>=', now()->toDateString())
            ->groupBy('area_id')
            ->get()
            ->keyBy('area_id');

        return view('admin.operations', [
            'areas'    => $areas,
            'capacity' => $capacity,
            'jobs'     => ScanJob::with(['property:id,uuid,title', 'technician:id,name', 'area:id,name'])
                ->whereIn('state', ['paid', 'scheduled', 'rescheduled', 'captured', 'processing'])
                ->orderBy('scheduled_for')
                ->limit(25)
                ->get(),
            'technicians' => User::where('staff_role', 'technician')->get(['id', 'name']),
        ]);
    }

    /** FR-M4-02: coverage is admin-managed, never hard-coded. */
    public function toggleCoverage(Area $area)
    {
        $before = ['is_scan_coverage' => $area->is_scan_coverage];

        $area->update(['is_scan_coverage' => ! $area->is_scan_coverage]);

        Audit::record('area.coverage_changed', $area, $before, [
            'is_scan_coverage' => $area->is_scan_coverage,
        ]);

        return back()->with(
            'status',
            $area->name.($area->is_scan_coverage ? ' is now open for 3D capture.' : ' is closed for 3D capture.')
        );
    }

    /**
     * Add bookable capacity.
     *
     * Capped at what a technician can genuinely service in a day, because a
     * calendar that offers more than the field team can deliver converts a
     * revenue feature into a queue of apologies (risk R2).
     */
    public function addSlots(Request $request, Area $area)
    {
        $data = $request->validate([
            'technician_id' => ['required', 'exists:users,id'],
            'from'          => ['required', 'date', 'after_or_equal:today'],
            'days'          => ['required', 'integer', 'min:1', 'max:30'],
            'times'         => ['required', 'array', 'min:1', 'max:3'],
            'times.*'       => ['date_format:H:i'],
        ]);

        $created = 0;
        $start = \Illuminate\Support\Carbon::parse($data['from']);

        for ($day = 0; $day < $data['days']; $day++) {
            $date = $start->copy()->addDays($day);

            if ($date->isSunday()) {
                continue;
            }

            foreach ($data['times'] as $time) {
                $slot = TechnicianSlot::firstOrCreate([
                    'area_id'       => $area->id,
                    'technician_id' => $data['technician_id'],
                    'slot_date'     => $date->toDateString(),
                    'slot_start'    => $time.':00',
                ], ['capacity' => 1, 'booked' => 0]);

                if ($slot->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        Audit::record('area.slots_added', $area, [], ['created' => $created]);

        return back()->with('status', $created.' new '.str('slot')->plural($created).' opened in '.$area->name.'.');
    }
}
