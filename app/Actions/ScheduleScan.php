<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\ScanJob;
use App\Models\TechnicianSlot;
use App\Notifications\CaptureBooked;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Booking the capture visit (FR-M4-05, FR-M4-06, FR-M4-07).
 *
 * The slot is claimed with a conditional update inside a transaction rather
 * than a read-then-write, because two listers booking the last slot in Ikoyi at
 * the same moment is exactly the case that would otherwise double-book a
 * technician and turn a paid feature into an apology.
 */
class ScheduleScan
{
    /**
     * @throws ValidationException
     */
    public function __invoke(Order $order, TechnicianSlot $slot): ScanJob
    {
        if (! $order->isPaid()) {
            throw ValidationException::withMessages([
                'slot' => 'This capture has not been paid for yet.',
            ]);
        }

        if ($order->scanJob()->exists()) {
            throw ValidationException::withMessages([
                'slot' => 'This capture is already scheduled.',
            ]);
        }

        $property = $order->property;

        if ($slot->area_id !== $property->area_id) {
            throw ValidationException::withMessages([
                'slot' => 'That slot is for a different area.',
            ]);
        }

        $job = DB::transaction(function () use ($order, $slot, $property) {
            // Claim the capacity first. The where-clause is the lock: if another
            // booking got there first, this updates zero rows and we stop.
            $claimed = TechnicianSlot::whereKey($slot->getKey())
                ->whereColumn('booked', '<', 'capacity')
                ->update(['booked' => DB::raw('booked + 1')]);

            if ($claimed === 0) {
                throw ValidationException::withMessages([
                    'slot' => 'That slot was taken while you were choosing. Pick another.',
                ]);
            }

            $slot->refresh();

            $job = ScanJob::create([
                'uuid'          => Str::uuid(),
                'property_id'   => $property->id,
                'order_id'      => $order->id,
                'area_id'       => $slot->area_id,
                'technician_id' => $slot->technician_id,
                'state'         => 'scheduled',
                'scheduled_for' => $slot->startsAt(),
            ]);

            Audit::record('scan.scheduled', $job, [], [
                'scheduled_for' => $job->scheduled_for->toIso8601String(),
                'technician_id' => $slot->technician_id,
                'order_id'      => $order->id,
            ], $order->user_id);

            return $job;
        });

        // After commit: a reminder about a visit that did not save is worse
        // than one that arrives a second late.
        $order->user->notify(new CaptureBooked($job->fresh()));

        return $job;
    }

    /** What the lister is offered: real capacity, in their property's area. */
    public function availableSlots(Order $order)
    {
        return TechnicianSlot::query()
            ->open()
            ->where('area_id', $order->property?->area_id)
            ->with('technician:id,name')
            ->orderBy('slot_date')
            ->orderBy('slot_start')
            ->limit(40)
            ->get();
    }

    /**
     * FR-M4-08. Releasing the old slot before claiming the new one is the wrong
     * order — if the new claim fails the lister would be left with neither.
     */
    public function reschedule(ScanJob $job, TechnicianSlot $slot): ScanJob
    {
        if (! $job->canReschedule()) {
            throw ValidationException::withMessages([
                'slot' => 'This visit is too close to reschedule. Call us instead.',
            ]);
        }

        return DB::transaction(function () use ($job, $slot) {
            $claimed = TechnicianSlot::whereKey($slot->getKey())
                ->whereColumn('booked', '<', 'capacity')
                ->update(['booked' => DB::raw('booked + 1')]);

            if ($claimed === 0) {
                throw ValidationException::withMessages([
                    'slot' => 'That slot was taken while you were choosing. Pick another.',
                ]);
            }

            $this->releaseSlotAt($job);

            $before = ['scheduled_for' => $job->scheduled_for?->toIso8601String()];

            $job->update([
                'state'             => 'scheduled',
                'scheduled_for'     => $slot->startsAt(),
                'technician_id'     => $slot->technician_id,
                'area_id'           => $slot->area_id,
                'reschedule_count'  => $job->reschedule_count + 1,
            ]);

            Audit::record('scan.rescheduled', $job, $before, [
                'scheduled_for' => $job->scheduled_for->toIso8601String(),
            ]);

            return $job->fresh();
        });
    }

    /** Give the capacity back so somebody else can book it. */
    private function releaseSlotAt(ScanJob $job): void
    {
        if (! $job->scheduled_for) {
            return;
        }

        TechnicianSlot::where('area_id', $job->area_id)
            ->whereDate('slot_date', $job->scheduled_for->toDateString())
            ->whereTime('slot_start', $job->scheduled_for->format('H:i:s'))
            ->where('booked', '>', 0)
            ->limit(1)
            ->update(['booked' => DB::raw('booked - 1')]);
    }
}
