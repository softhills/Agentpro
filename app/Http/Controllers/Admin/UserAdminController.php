<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;

/** FR-M12-03: user and KYC administration. */
class UserAdminController extends Controller
{
    public function index(Request $request)
    {
        $users = User::query()
            ->when($request->filled('q'), fn ($q) => $q->where(function ($w) use ($request) {
                $term = '%'.$request->query('q').'%';
                $w->where('name', 'like', $term)->orWhere('email', 'like', $term);
            }))
            ->when($request->filled('state'), fn ($q) => $q->where('verification_state', $request->query('state')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->query('category')))
            ->withCount('properties')
            // Anything needing a decision floats to the top; verified accounts
            // are the ones nobody has to look at.
            ->orderByRaw("FIELD(verification_state,'pending','rejected','unverified','suspended','verified')")
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.users', [
            'users'  => $users,
            'counts' => User::selectRaw('verification_state, COUNT(*) c')
                ->groupBy('verification_state')->pluck('c', 'verification_state'),
        ]);
    }

    /**
     * Manual KYC override.
     *
     * Recorded under its own action rather than reusing the vendor's: a person
     * deciding to trust an account and a vendor check passing are different
     * events, and an audit trail that cannot tell them apart is worth less.
     */
    public function updateVerification(Request $request, User $user)
    {
        $data = $request->validate([
            'verification_state' => ['required', 'in:unverified,pending,verified,rejected,suspended'],
            'note'               => ['nullable', 'string', 'max:500'],
        ]);

        $before = ['verification_state' => $user->verification_state];

        $user->forceFill([
            'verification_state'  => $data['verification_state'],
            'verified_at'         => $data['verification_state'] === 'verified' ? now() : null,
            'verification_vendor' => 'manual',
        ])->save();

        Audit::record('user.verification_set_manually', $user, $before, [
            'verification_state' => $data['verification_state'],
            'note'               => $data['note'] ?? null,
        ]);

        return back()->with('status', $user->name.' is now '.$data['verification_state'].'.');
    }
}
