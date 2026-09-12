<?php

namespace App\Http\Controllers;

use App\Models\Interaction;
use App\Models\Property;
use App\Support\Audit;
use App\Support\Vocab;
use Illuminate\Http\Request;

/**
 * Seeker interactions (FR-M9-01, FR-M9-02, FR-M9-10, FR-M6-07, FR-M6-08).
 *
 * These are also what define the audience for listing alerts (FR-M9-04), so an
 * interaction recorded here is a standing statement of interest, not analytics.
 */
class InteractionController extends Controller
{
    /** FR-M9-01: save, and un-save. */
    public function toggleSave(Request $request, Property $property)
    {
        $existing = $this->find($request, $property, 'save');

        if ($existing) {
            $existing->delete();

            return back()->with('status', 'Removed from your saved listings.');
        }

        $this->record($request, $property, 'save');

        // Saving a hidden listing is a change of mind; drop the hide so the
        // two do not contradict each other.
        $this->find($request, $property, 'hide')?->delete();

        return back()->with('status', 'Saved. We will tell you if anything changes.');
    }

    /** FR-M9-10: hide, so it stops appearing in results. */
    public function toggleHide(Request $request, Property $property)
    {
        $existing = $this->find($request, $property, 'hide');

        if ($existing) {
            $existing->delete();

            return back()->with('status', 'This listing will show in your results again.');
        }

        $this->record($request, $property, 'hide');

        return back()->with('status', 'Hidden from your results.');
    }

    /** FR-M6-08 */
    public function rate(Request $request, Property $property)
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'note'   => ['nullable', 'string', 'max:1000'],
        ]);

        $this->record($request, $property, 'rate', $data);

        return back()->with('status', 'Thank you — your rating helps other seekers.');
    }

    /** FR-M6-07: report, into the moderation queue. */
    public function report(Request $request, Property $property)
    {
        $data = $request->validate([
            'reason_code' => ['required', 'in:'.implode(',', array_keys(Vocab::REPORT_REASONS))],
            'note'        => ['nullable', 'string', 'max:1000'],
        ]);

        $this->record($request, $property, 'report', $data);

        Audit::record('listing.reported', $property, [], ['reason_code' => $data['reason_code']]);

        return back()->with(
            'status',
            'Reported. We review fraud reports within '.config('agentpro.sla.fraud_report_hours').' working hours.'
        );
    }

    /**
     * FR-M9-02: every contact initiation is logged against the listing.
     *
     * Recorded before the redirect so the count is right even when the seeker
     * abandons the call — the lister is paying for exposure, and an enquiry
     * that was started is still signal.
     */
    public function contact(Request $request, Property $property)
    {
        $data = $request->validate([
            'mode' => ['required', 'in:phone,whatsapp,email'],
        ]);

        $this->record($request, $property, 'contact', ['contact_mode' => $data['mode']]);

        return response()->json(['recorded' => true]);
    }

    private function find(Request $request, Property $property, string $kind): ?Interaction
    {
        return Interaction::where('user_id', $request->user()->id)
            ->where('property_id', $property->id)
            ->where('kind', $kind)
            ->first();
    }

    private function record(Request $request, Property $property, string $kind, array $extra = []): Interaction
    {
        // Unique on (user, property, kind), so repeating an action updates
        // rather than accumulating duplicates.
        return Interaction::updateOrCreate(
            [
                'user_id'     => $request->user()->id,
                'property_id' => $property->id,
                'kind'        => $kind,
            ],
            $extra
        );
    }
}
