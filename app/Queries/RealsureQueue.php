<?php

namespace App\Queries;

use App\Enums\LifecycleState;
use App\Models\Property;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;

/**
 * What a RealSure Officer should work on, in the order they should work on it.
 *
 * The ordering is the whole design. An officer opening this screen needs to be
 * told what to do next, not handed a list of every published listing to sort
 * through — and the thing that must never sit at the bottom is work somebody
 * has already paid for.
 *
 * RealSure is sold as an engagement rather than through a checkout, so a paid
 * `realsure` order with no badge on the listing is money taken for something
 * not yet delivered. That is the same failure the 3D upgrade guards against in
 * FR-M4-07, and it deserves the same treatment: surfaced first, never a dead
 * end.
 */
class RealsureQueue
{
    /**
     * Listings that have been paid for and not finished.
     *
     * @return \Illuminate\Support\Collection<int, Property>
     */
    public function paidAndOutstanding()
    {
        return $this->base()
            ->whereNull('properties.realsure_verified_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('orders')
                ->whereColumn('orders.property_id', 'properties.id')
                ->where('orders.item_type', 'realsure')
                ->where('orders.state', 'paid'))
            // Oldest purchase first. A queue sorted newest-first starves
            // whoever has been waiting longest, which is exactly backwards.
            ->orderBy('properties.published_at')
            ->get();
    }

    /**
     * Started but not finished — some checks recorded, no badge.
     *
     * @return \Illuminate\Support\Collection<int, Property>
     */
    public function inProgress()
    {
        return $this->base()
            ->whereNull('properties.realsure_verified_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('realsure_records')
                ->whereColumn('realsure_records.property_id', 'properties.id')
                ->where('realsure_records.completed', true))
            ->orderBy('properties.published_at')
            ->get();
    }

    /**
     * Carrying the badge.
     *
     * Listed rather than filed away, because revocation has to be reachable.
     * A console that can only grant is a console that quietly makes every past
     * mistake permanent.
     *
     * @return \Illuminate\Support\Collection<int, Property>
     */
    public function verified()
    {
        return $this->base()
            ->whereNotNull('properties.realsure_verified_at')
            ->orderByDesc('properties.realsure_verified_at')
            ->get();
    }

    /**
     * Everything else published, for searching.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function untouched(?string $term = null)
    {
        return $this->base()
            ->whereNull('properties.realsure_verified_at')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('realsure_records')
                ->whereColumn('realsure_records.property_id', 'properties.id')
                ->where('realsure_records.completed', true))
            ->when($term, fn ($q) => $q->where(fn ($w) => $w
                ->where('properties.title', 'like', '%'.$term.'%')
                ->orWhere('properties.address_line', 'like', '%'.$term.'%')
                ->orWhere('properties.city', 'like', '%'.$term.'%')))
            ->orderByDesc('properties.published_at')
            ->paginate(20)
            ->withQueryString();
    }

    /** Totals for the header. */
    public function summary(): array
    {
        $published = Property::where('lifecycle_state', LifecycleState::Published->value)->count();
        $verified = Property::where('lifecycle_state', LifecycleState::Published->value)
            ->whereNotNull('realsure_verified_at')->count();

        return [
            'published' => $published,
            'verified'  => $verified,
            // O1's measure, on the screen of the person who moves it. A target
            // nobody can see is a target nobody works towards.
            'share'     => $published > 0 ? round($verified / $published * 100) : 0,
            'target'    => 25,
            'paid'      => $this->paidAndOutstanding()->count(),
        ];
    }

    /**
     * How far through the checks a listing is, for the queue rows.
     *
     * Counts verification separately from production, because "four of ten"
     * hides whether the four were checks or photographs — and only one of those
     * moves a listing towards the badge.
     */
    public static function progressFor(Property $property): array
    {
        $done = $property->realsureRecords->where('completed', true)->pluck('component')->all();

        return [
            'verification' => count(array_intersect($done, Vocab::REALSURE_VERIFICATION)),
            'verification_of' => count(Vocab::REALSURE_VERIFICATION),
            'production'   => count(array_intersect($done, Vocab::REALSURE_PRODUCTION)),
            'production_of' => count(Vocab::REALSURE_PRODUCTION),
        ];
    }

    private function base()
    {
        return Property::query()
            ->where('properties.lifecycle_state', LifecycleState::Published->value)
            ->with(['lister:id,name', 'area:id,name,city', 'realsureRecords']);
    }
}
