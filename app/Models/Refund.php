<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A refund and its progress (FR-M11-05).
 *
 * Separate from the order because it has its own lifecycle and its own ways of
 * failing. The state here is ours; `provider_status` is the provider's word for
 * the same thing, kept alongside rather than folded in, because the two
 * disagreeing is itself worth being able to see.
 */
class Refund extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount'       => 'decimal:2',
        'approved_at'  => 'datetime',
        'submitted_at' => 'datetime',
        'processed_at' => 'datetime',
        'expected_at'  => 'date',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Money has left the business but not yet reached the payer. */
    public function isInFlight(): bool
    {
        return $this->state === 'submitted';
    }

    /** Waiting on a second person before anything is sent anywhere. */
    public function needsApproval(): bool
    {
        return $this->state === 'requested';
    }

    /**
     * Sent to the provider and still not confirmed after the grace period.
     *
     * Not an error on its own — Nigerian card refunds genuinely take days — but
     * past this point it stops being "in progress" and starts being something
     * somebody has to chase.
     */
    public function isStuck(): bool
    {
        if (! $this->isInFlight() || $this->submitted_at === null) {
            return false;
        }

        return $this->submitted_at->diffInDays(now()) > (int) config('agentpro.refunds.stale_after_days');
    }

    public function stateLabel(): string
    {
        return match ($this->state) {
            'requested' => 'Awaiting approval',
            'submitted' => 'With the provider',
            'processed' => 'Paid back',
            'failed'    => 'Failed',
            'cancelled' => 'Cancelled',
            default     => $this->state,
        };
    }
}
