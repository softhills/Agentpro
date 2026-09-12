<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A 3D capture visit (FR-M4-06).
 *
 * Deliberately separate from the order. A paid order with no scan job is a
 * recoverable state the lister can complete later; a scan job that failed to
 * create must never look like a lost payment.
 */
class ScanJob extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'attended_at'   => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function stateLabel(): string
    {
        return match ($this->state) {
            'requested'   => 'Awaiting payment',
            'paid'        => 'Paid — choose a date',
            'scheduled'   => 'Scheduled',
            'captured'    => 'Captured',
            'processing'  => 'Processing',
            'live'        => 'Live on the listing',
            'rescheduled' => 'Rescheduled',
            'cancelled'   => 'Cancelled',
            'refunded'    => 'Refunded',
            default       => $this->state,
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this->state, ['live', 'cancelled', 'refunded'], true);
    }

    /**
     * Q4 is still open on the reschedule window, so the policy lives in config
     * rather than being hard-coded into a comparison here.
     */
    public function canReschedule(): bool
    {
        $hours = (int) config('agentpro.scan.reschedule_notice_hours');

        return $this->state === 'scheduled'
            && $this->scheduled_for?->isAfter(now()->addHours($hours));
    }
}
