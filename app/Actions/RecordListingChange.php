<?php

namespace App\Actions;

use App\Models\Property;
use App\Models\Unit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Deciding what is worth telling people about (FR-M9-05, FR-M9-06).
 *
 * The requirement says a *material* update alerts everyone who interacted with
 * the listing, and that cosmetic edits do not. That distinction is the whole
 * feature: an alert for every typo correction trains people to ignore alerts,
 * and then the price drop they actually wanted goes unread too.
 *
 * Material means: the price, whether it is still available, its lifecycle
 * status, or the arrival of a 3D tour or video. Not the description, not the
 * photographs, not the amenity list.
 *
 * Changes accumulate in a window rather than firing immediately, so an agent
 * correcting four fields in one sitting produces one notification that names
 * all four.
 */
class RecordListingChange
{
    /**
     * @param  array<string,mixed>  $before
     */
    public function fromPropertyUpdate(Property $property, array $before): void
    {
        $changes = [];

        $stateBefore = $before['lifecycle_state'] ?? null;
        $stateAfter  = $property->lifecycle_state->value;

        if ($stateBefore && $stateBefore !== $stateAfter && in_array($stateAfter, ['sold', 'rented'], true)) {
            $changes[] = 'It is now marked '.$stateAfter.'.';
        }

        if ($stateBefore && $stateBefore === 'published' && $stateAfter === 'unpublished') {
            $changes[] = 'It has been taken off the market.';
        }

        $this->queue($property, $changes);
    }

    public function fromPriceChange(Unit $unit, float $previous): void
    {
        $now = (float) $unit->price;

        if (abs($now - $previous) < 0.01) {
            return;
        }

        $direction = $now < $previous ? 'dropped' : 'increased';

        $this->queue($unit->property, [
            'The price has '.$direction.' from '.Money::naira($previous).' to '.Money::naira($now).'.',
        ]);
    }

    public function fromNewMedia(Property $property, string $kind): void
    {
        $label = match ($kind) {
            'tour_3d' => 'A 3D tour has been added.',
            'video'   => 'A walkthrough video has been added.',
            default   => null,
        };

        if ($label) {
            $this->queue($property, [$label]);
        }
    }

    /**
     * Add to the open window for this listing, or open one.
     *
     * The window is deliberately keyed on the listing rather than on the
     * recipient: the thing being batched is "what happened to this property",
     * and every interested person should get the same account of it.
     */
    private function queue(Property $property, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $minutes = (int) config('agentpro.notifications.batch_window_minutes');

        DB::transaction(function () use ($property, $changes, $minutes) {
            $pending = DB::table('pending_listing_alerts')
                ->where('property_id', $property->id)
                ->whereNull('dispatched_at')
                ->lockForUpdate()
                ->first();

            if ($pending) {
                $existing = json_decode($pending->changes, true) ?: [];

                DB::table('pending_listing_alerts')
                    ->where('id', $pending->id)
                    ->update([
                        // array_values(array_unique(...)) so correcting the same
                        // field twice in one window is still one line.
                        'changes'    => json_encode(array_values(array_unique(array_merge($existing, $changes)))),
                        'updated_at' => now(),
                    ]);

                return;
            }

            DB::table('pending_listing_alerts')->insert([
                'property_id'      => $property->id,
                'changes'          => json_encode(array_values(array_unique($changes))),
                'window_closes_at' => now()->addMinutes($minutes),
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        });
    }
}
