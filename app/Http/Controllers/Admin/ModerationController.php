<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ModerateListing;
use App\Enums\LifecycleState;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Queries\DuplicateCandidates;
use App\Support\Vocab;
use Illuminate\Http\Request;

class ModerationController extends Controller
{
    /** FR-M12-01 / FR-M12-06: the queue, with the SLA visible on it. */
    public function queue(Request $request)
    {
        $filter = $request->query('state', 'awaiting');

        $query = Property::query()
            ->with(['lister:id,name,verification_state', 'area', 'units', 'media'])
            ->withCount(['media as photo_count' => fn ($q) => $q->where('kind', 'photo')]);

        $query = match ($filter) {
            'published'   => $query->where('lifecycle_state', LifecycleState::Published->value),
            'rejected'    => $query->where('lifecycle_state', LifecycleState::Rejected->value),
            'unpublished' => $query->where('lifecycle_state', LifecycleState::Unpublished->value),
            default       => $query->whereIn('lifecycle_state', [
                LifecycleState::Submitted->value,
                LifecycleState::UnderReview->value,
            ]),
        };

        // Oldest first. A queue sorted newest-first quietly starves the
        // submissions closest to breaching the SLA.
        $properties = $query->orderBy('submitted_at')->paginate(25)->withQueryString();

        $slaHours = (int) config('agentpro.sla.approval_hours');

        $properties->each(function (Property $p) use ($slaHours) {
            $p->waitingHours = $p->submitted_at
                ? (int) $p->submitted_at->diffInHours(now())
                : null;
            $p->breachesSla = $p->waitingHours !== null && $p->waitingHours > $slaHours;
        });

        return view('admin.queue', [
            'properties' => $properties,
            'filter'     => $filter,
            'slaHours'   => $slaHours,
            'counts'     => [
                'awaiting' => Property::whereIn('lifecycle_state', [
                    LifecycleState::Submitted->value,
                    LifecycleState::UnderReview->value,
                ])->count(),
                'published' => Property::where('lifecycle_state', LifecycleState::Published->value)->count(),
                'rejected'  => Property::where('lifecycle_state', LifecycleState::Rejected->value)->count(),
            ],
        ]);
    }

    /**
     * FR-M12-02: the side-by-side review view.
     *
     * Everything a decision needs on one screen — content, media, declared
     * title, the fee breakdown and any duplicate flags — because a moderator
     * working a queue against a six-hour SLA cannot be clicking through tabs.
     */
    public function review(Property $property, ModerateListing $moderate, DuplicateCandidates $duplicates)
    {
        $property->load([
            'units.feeLines', 'media', 'titleClaims', 'amenities',
            'area', 'lister', 'realsureRecords',
        ]);

        // Claiming on open means two moderators do not silently work the same
        // listing. It is not a lock — it is a signal.
        $moderate->claim($property, request()->user());

        return view('admin.review', [
            'property'   => $property->fresh([
                'units.feeLines', 'media', 'titleClaims', 'amenities', 'area', 'lister',
            ]),
            'unit'       => $property->headlineUnit(),
            'duplicates' => $duplicates->for($property),
            'rejectReasons'    => Vocab::REJECT_REASONS,
            'unpublishReasons' => Vocab::UNPUBLISH_REASONS,
        ]);
    }

    public function approve(Property $property, ModerateListing $moderate)
    {
        $moderate->approve($property, request()->user());

        return redirect()
            ->route('admin.queue')
            ->with('status', 'Published: '.$property->title);
    }

    public function reject(Request $request, Property $property, ModerateListing $moderate)
    {
        $data = $request->validate([
            'reason_code' => ['required', 'in:'.implode(',', array_keys(Vocab::REJECT_REASONS))],
            // Required, not optional: the note is what the lister actually reads.
            'note'        => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'note.required' => 'Tell the lister what to fix — a reason code alone is not actionable.',
        ]);

        $moderate->reject($property, $request->user(), $data['reason_code'], $data['note']);

        return redirect()
            ->route('admin.queue')
            ->with('status', 'Returned to lister: '.$property->title);
    }

    public function unpublish(Request $request, Property $property, ModerateListing $moderate)
    {
        $data = $request->validate([
            'reason_code' => ['required', 'in:'.implode(',', array_keys(Vocab::UNPUBLISH_REASONS))],
            'note'        => ['nullable', 'string', 'max:1000'],
        ]);

        $moderate->unpublish($property, $request->user(), $data['reason_code'], $data['note'] ?? null);

        return redirect()
            ->route('admin.queue')
            ->with('status', 'Unpublished: '.$property->title);
    }
}
