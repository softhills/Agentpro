<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\Request;

/**
 * Every listing in every state.
 *
 * Distinct from the moderation queue, which deliberately shows only what is
 * awaiting a decision. This is where you go to find one specific listing,
 * whatever has happened to it.
 */
class ListingAdminController extends Controller
{
    public function index(Request $request)
    {
        $listings = Property::query()
            ->when($request->filled('state'), fn ($q) => $q->where('lifecycle_state', $request->query('state')))
            ->when($request->filled('q'), fn ($q) => $q->where(function ($w) use ($request) {
                $term = '%'.$request->query('q').'%';
                $w->where('title', 'like', $term)->orWhere('address_line', 'like', $term);
            }))
            ->with(['lister:id,name,verification_state', 'area', 'units'])
            ->withCount(['media as photo_count' => fn ($q) => $q->where('kind', 'photo')])
            ->orderByDesc('updated_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.listings', [
            'listings' => $listings,
            'counts'   => Property::selectRaw('lifecycle_state, COUNT(*) c')
                ->groupBy('lifecycle_state')->pluck('c', 'lifecycle_state'),
        ]);
    }
}
