<?php

namespace App\Http\Controllers\Realsure;

use App\Actions\RecordRealsureCheck;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Queries\RealsureQueue;
use App\Support\Vocab;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The RealSure Officer's console (FR-M6-01 to FR-M6-03).
 *
 * Until now the badge, the component breakdown and the officer of record all
 * worked — but only because the database seeder wrote those rows. There was no
 * way for a real officer to record what they had actually checked, which meant
 * that in a live deployment no listing could ever have earned the badge, and
 * the trust mark the whole product rests on was decorative.
 */
class ConsoleController extends Controller
{
    public function __construct(private RecordRealsureCheck $checks) {}

    public function index(Request $request, RealsureQueue $queue)
    {
        return view('realsure.queue', [
            'summary'    => $queue->summary(),
            'paid'       => $queue->paidAndOutstanding(),
            'inProgress' => $queue->inProgress(),
            'verified'   => $queue->verified(),
            'untouched'  => $queue->untouched($request->query('q')),
            'term'       => $request->query('q'),
        ]);
    }

    /** The record sheet for one listing. */
    public function show(Property $property)
    {
        $property->load(['realsureRecords.officer:id,name', 'lister:id,name', 'area', 'titleClaims', 'units']);

        // Keyed by component so the form can render all ten in the PRD's order
        // whether or not a row exists yet — an unrecorded component is a real
        // state, not a missing one.
        $records = $property->realsureRecords->keyBy('component');

        return view('realsure.record', [
            'property'   => $property,
            'records'    => $records,
            'components' => Vocab::REALSURE_COMPONENTS,
            'blockers'   => $this->checks->blockers($property),
            'progress'   => RealsureQueue::progressFor($property),
        ]);
    }

    /** Record, or withdraw, one component. */
    public function record(Request $request, Property $property)
    {
        $data = $request->validate([
            'component'    => ['required', 'string', 'in:'.implode(',', array_keys(Vocab::REALSURE_COMPONENTS))],
            'completed'    => ['nullable', 'boolean'],
            'completed_on' => ['nullable', 'date', 'before_or_equal:today'],
            // The reference into wherever the actual document lives — a search
            // report number, a file reference. The platform does not store the
            // document itself; this is the thread back to it.
            'evidence_ref' => ['nullable', 'string', 'max:255'],
            'notes'        => ['nullable', 'string', 'max:2000'],
        ], [
            'completed_on.before_or_equal' => 'A check cannot be completed on a future date.',
        ]);

        try {
            $this->checks->record(
                $property,
                $data['component'],
                $request->user(),
                (bool) ($data['completed'] ?? false),
                isset($data['completed_on']) ? Carbon::parse($data['completed_on']) : null,
                $data['evidence_ref'] ?? null,
                $data['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['component' => $e->getMessage()]);
        }

        return back()->with('status', Vocab::REALSURE_COMPONENTS[$data['component']].' updated.');
    }

    public function grant(Request $request, Property $property)
    {
        try {
            $this->checks->grant($property, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['grant' => $e->getMessage()]);
        }

        return back()->with('status', 'Badge granted. The lister has been told.');
    }

    public function revoke(Request $request, Property $property)
    {
        $data = $request->validate([
            'why' => ['required', 'string', 'min:10', 'max:255'],
        ], [
            'why.min' => 'Say what was wrong, in enough words that somebody reading this in six '
                        .'months can follow it.',
        ]);

        try {
            $this->checks->revoke($property, $request->user(), $data['why']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['why' => $e->getMessage()]);
        }

        return back()->with('status', 'Badge removed. The lister has been told why.');
    }
}
