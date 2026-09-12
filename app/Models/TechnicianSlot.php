<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bookable capture capacity (FR-M4-05).
 *
 * Slots exist so the calendar offers what the field team can actually service.
 * Risk R2 is that capacity, not demand, throttles the only paid feature in R1 —
 * a calendar that accepts more bookings than there are technicians converts a
 * revenue feature into a queue of angry listers.
 */
class TechnicianSlot extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'slot_date' => 'date',
    ];

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function hasCapacity(): bool
    {
        return $this->booked < $this->capacity;
    }

    public function startsAt(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::parse(
            $this->slot_date->format('Y-m-d').' '.$this->slot_start
        );
    }

    public function label(): string
    {
        $start = $this->startsAt();

        return $start->format('D j M, g:ia').' – '.$start->copy()->addHours($this->capacity > 0 ? 2 : 2)->format('g:ia');
    }

    public function scopeOpen($q)
    {
        return $q->whereColumn('booked', '<', 'capacity')
                 ->whereDate('slot_date', '>=', now()->toDateString());
    }
}
