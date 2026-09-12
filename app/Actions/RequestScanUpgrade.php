<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\Property;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Requesting a 3D capture (FR-M4-01, FR-M4-02, FR-M11-03).
 *
 * Eligibility is enforced here rather than in the view, because the checkout
 * route is reachable by anyone who can post a form. An ineligible property must
 * not be able to reach payment at all — taking money for a scan that cannot be
 * performed is the worst failure available in this flow.
 */
class RequestScanUpgrade
{
    /**
     * Why this property cannot be scanned. Empty means it can.
     *
     * Returned as reasons rather than a boolean so FR-M4-03 can tell the lister
     * what is wrong instead of greying out a button with no explanation.
     *
     * @return list<string>
     */
    public function ineligibility(Property $property): array
    {
        $reasons = [];

        if ($property->build_status !== 'fully_built') {
            $reasons[] = 'Capture is only possible on a completed building. This listing is under construction.';
        }

        if ($property->listing_type === 'land') {
            $reasons[] = 'There is nothing to capture on a land listing.';
        }

        if (! $property->area || ! $property->area->is_scan_coverage) {
            $reasons[] = $property->area
                ? $property->area->name.' is outside our capture coverage at the moment.'
                : 'This listing has no area set, so we cannot check capture coverage.';
        }

        if (! in_array($property->lifecycle_state->value, ['published', 'submitted', 'under_review'], true)) {
            $reasons[] = 'Publish the listing before adding a 3D tour.';
        }

        if ($property->media()->where('kind', 'tour_3d')->exists()) {
            $reasons[] = 'This listing already has a 3D tour.';
        }

        if ($this->openOrderFor($property)) {
            $reasons[] = 'There is already a capture in progress for this listing.';
        }

        return $reasons;
    }

    public function isEligible(Property $property): bool
    {
        return $this->ineligibility($property) === [];
    }

    /**
     * @throws ValidationException
     */
    public function __invoke(Property $property, User $user): Order
    {
        $reasons = $this->ineligibility($property);

        if ($reasons !== []) {
            throw ValidationException::withMessages(['scan' => $reasons]);
        }

        return DB::transaction(function () use ($property, $user) {
            // FR-M11-03 / SEC-05: the price is read here, server-side, from
            // versioned configuration. Nothing in the request influences it.
            $order = Order::create([
                'uuid'          => Str::uuid(),
                'user_id'       => $user->id,
                'property_id'   => $property->id,
                'item_type'     => 'scan_3d',
                'amount'        => (float) config('agentpro.prices.scan_3d'),
                'currency'      => 'NGN',
                'price_version' => config('agentpro.prices.version'),
                'state'         => 'pending',
            ]);

            Audit::record('order.created', $order, [], [
                'item_type'     => 'scan_3d',
                'amount'        => $order->amount,
                'price_version' => $order->price_version,
                'property_id'   => $property->id,
            ], $user->id);

            return $order;
        });
    }

    private function openOrderFor(Property $property): bool
    {
        return Order::where('property_id', $property->id)
            ->where('item_type', 'scan_3d')
            ->whereIn('state', ['pending', 'paid'])
            ->exists();
    }
}
