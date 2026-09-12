<?php

namespace App\Http\Controllers\Technician;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Models\ScanJob;
use App\Actions\RecordListingChange;
use App\Notifications\TourIsLive;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The field tool (FR-M4-09, FR-M4-10).
 *
 * Built for someone standing outside a gate in Lekki on a phone, so it carries
 * the two things that actually get them in — the what3words reference, because
 * street addresses here frequently do not resolve, and the lister's number.
 */
class AssignmentController extends Controller
{
    public function index(Request $request)
    {
        $jobs = ScanJob::query()
            ->where('technician_id', $request->user()->id)
            ->whereIn('state', ['scheduled', 'rescheduled', 'captured', 'processing'])
            ->with(['property.area', 'property.lister:id,name,phone'])
            ->orderBy('scheduled_for')
            ->get();

        return view('technician.assignments', ['jobs' => $jobs]);
    }

    /** Attendance is recorded separately from capture: arriving and being let in are different events. */
    public function attend(ScanJob $job, Request $request)
    {
        $this->authoriseJob($job, $request);

        $job->update(['attended_at' => now()]);

        Audit::record('scan.attended', $job, [], ['attended_at' => now()->toIso8601String()]);

        return back()->with('status', 'Attendance recorded.');
    }

    /**
     * FR-M4-09 / FR-M4-10. Attaching the capture publishes the tour and moves
     * the job to live in one transaction — a capture reference recorded without
     * the tour appearing is the state that generates support tickets.
     */
    public function capture(Request $request, ScanJob $job)
    {
        $this->authoriseJob($job, $request);

        $data = $request->validate([
            // Matterport space id.
            'capture_reference' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'notes'             => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($job, $data) {
            $before = ['state' => $job->state];

            $job->update([
                'state'             => 'live',
                'capture_reference' => $data['capture_reference'],
                'notes'             => $data['notes'] ?? null,
                'attended_at'       => $job->attended_at ?? now(),
            ]);

            $job->property->media()->create([
                'uuid'         => Str::uuid(),
                'kind'         => 'tour_3d',
                'provider'     => 'matterport',
                'provider_ref' => $data['capture_reference'],
                'bytes'        => 4_194_304,
                // FR-M3-10: this is an Agentpro capture, and the listing says so.
                'source'       => 'agentpro_technician',
                'captured_at'  => now(),
                'moderation_state' => 'approved',
                'sort_order'   => (int) $job->property->media()->max('sort_order') + 1,
            ]);

            // FR-M4-10 / FR-M9-05: a new tour is a material change, so the
            // listing counts as updated and interacting seekers are alerted.
            $job->property->update(['content_updated_at' => now()]);

            Audit::record('scan.captured', $job, $before, [
                'state'             => 'live',
                'capture_reference' => $data['capture_reference'],
            ]);
        });

        // After commit. The lister is told their tour is live, and everyone
        // who saved the listing is queued into the batching window — a new
        // tour is a material change (FR-M9-05).
        $property = $job->property->fresh();
        $property->lister->notify(new TourIsLive($property));
        app(RecordListingChange::class)->fromNewMedia($property, 'tour_3d');

        return redirect()
            ->route('technician.assignments')
            ->with('status', 'Tour attached and live on the listing.');
    }

    private function authoriseJob(ScanJob $job, Request $request): void
    {
        abort_unless(
            $job->technician_id === $request->user()->id || $request->user()->isStaff('admin'),
            404
        );
    }
}
