<?php

namespace App\Http\Controllers\Lister;

use App\Actions\SubmitListingForReview;
use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, SubmitListingForReview $submitter)
    {
        $user = $request->user();

        $properties = Property::query()
            ->where(function ($q) use ($user) {
                $q->where('lister_id', $user->id);

                // FR-M1-08: firm and developer seats share inventory.
                if ($user->organisation_id) {
                    $q->orWhere('organisation_id', $user->organisation_id);
                }
            })
            ->with(['units.feeLines', 'media', 'titleClaims', 'area', 'lister'])
            ->orderByRaw("FIELD(lifecycle_state, 'rejected','draft','submitted','under_review','published','unpublished','expired','sold','rented')")
            ->orderByDesc('updated_at')
            ->get();

        // FR-M2-08: the lister sees days remaining, not just an expiry date —
        // a date alone does not prompt anyone to renew.
        $properties->each(function (Property $property) use ($submitter) {
            $property->daysRemaining = $property->expires_at
                ? (int) now()->startOfDay()->diffInDays($property->expires_at->startOfDay(), false)
                : null;

            // Shown inline so a lister can see exactly what is blocking a draft
            // rather than discovering it when submission fails.
            $property->blockers = in_array($property->lifecycle_state->value, ['draft', 'rejected'], true)
                ? $submitter->problems($property)
                : [];
        });

        return view('lister.dashboard', [
            'user'       => $user,
            'properties' => $properties,
            'counts'     => [
                'published' => $properties->where('lifecycle_state.value', 'published')->count(),
                'draft'     => $properties->whereIn('lifecycle_state.value', ['draft', 'rejected'])->count(),
                'review'    => $properties->whereIn('lifecycle_state.value', ['submitted', 'under_review'])->count(),
                'expiring'  => $properties->filter(fn ($p) => $p->daysRemaining !== null && $p->daysRemaining <= 7 && $p->daysRemaining >= 0)->count(),
            ],
        ]);
    }
}
