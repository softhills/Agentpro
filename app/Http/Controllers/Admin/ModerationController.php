<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ModerateListing;
use App\Actions\UnlistListing;
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
            'closeOutcomes'    => Vocab::CLOSE_OUTCOMES,
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

    /**
     * FR-M2-07 / FR-M2-09: take a live listing off the market.
     *
     * Asks the same question the lister's own form asks — sold, rented, or
     * something else — because it is the same question, and an admin who knows
     * the flat was let has no business recording that as an anonymous
     * "unpublished". The difference is the reason list: a moderator may record
     * findings (fraud, a title dispute) that a lister may not.
     */
    public function unlist(Request $request, Property $property, UnlistListing $unlister)
    {
        $data = $request->validate([
            'outcome'     => ['required', 'in:'.implode(',', array_keys(Vocab::CLOSE_OUTCOMES))],
            'reason_code' => [
                'required_if:outcome,other', 'nullable',
                'in:'.implode(',', array_keys(Vocab::UNPUBLISH_REASONS)),
            ],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'reason_code.required_if' => 'Record why this listing is being taken down.',
        ]);

        if (! $property->lifecycle_state->canBeUnlisted()) {
            return back()->withErrors([
                'outcome' => 'This listing is not on the market, so there is nothing to take down.',
            ]);
        }

        $unlister->handle(
            $property,
            $request->user(),
            $data['outcome'],
            $data['reason_code'] ?? null,
            $data['note'] ?? null,
        );

        return redirect()->route('admin.queue')->with('status', match ($data['outcome']) {
            'sold'   => 'Recorded as sold: '.$property->title,
            'rented' => 'Recorded as rented: '.$property->title,
            default  => 'Unpublished: '.$property->title,
        });
    }

    /** Puts a listing closed as sold or rented back on the market. */
    public function relist(Request $request, Property $property, UnlistListing $unlister)
    {
        if (! $property->lifecycle_state->canBeRelisted()) {
            return back()->withErrors([
                'outcome' => 'Only a listing closed as sold or rented can be put back on the market.',
            ]);
        }

        $unlister->relist($property, $request->user());

        return redirect()->route('admin.queue')->with('status', 'Back on the market: '.$property->title);
    }
}
