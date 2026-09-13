<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An access or erasure request (FR-M1-09).
 *
 * @see \App\Support\PersonalData for what an erasure actually does to each table.
 */
class DataRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'executes_at'   => 'datetime',
            'expires_at'    => 'datetime',
            'downloaded_at' => 'datetime',
            'completed_at'  => 'datetime',
            'cancelled_at'  => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        // withTrashed, because the one request guaranteed to outlive its user is
        // the erasure that removed them, and a completed erasure that cannot
        // name who it was for is useless as a record of having honoured it.
        return $this->belongsTo(User::class)->withTrashed();
    }

    // ------------------------------------------------------------------- state

    public function isOpen(): bool
    {
        return in_array($this->state, ['pending', 'ready'], true);
    }

    /** An export whose file is still there and still in date. */
    public function isDownloadable(): bool
    {
        return $this->kind === 'export'
            && $this->state === 'ready'
            && $this->file_path !== null
            && $this->expires_at?->isFuture() === true;
    }

    /** An erasure still inside its cooling-off period, so still stoppable. */
    public function isCancellable(): bool
    {
        return $this->state === 'pending'
            && ($this->kind === 'export' || $this->executes_at?->isFuture() === true);
    }

    public function stateLabel(): string
    {
        return match (true) {
            $this->kind === 'export' && $this->state === 'pending'   => 'Being prepared',
            $this->kind === 'export' && $this->state === 'ready'     => 'Ready to download',
            $this->kind === 'erasure' && $this->state === 'pending'  => 'Scheduled',
            $this->state === 'completed' => $this->kind === 'export' ? 'Downloaded' : 'Carried out',
            $this->state === 'expired'   => 'Expired unread',
            $this->state === 'cancelled' => 'Cancelled',
            $this->state === 'refused'   => 'Not possible yet',
            $this->state === 'failed'    => 'Something went wrong',
            default                      => ucfirst($this->state),
        };
    }
}
