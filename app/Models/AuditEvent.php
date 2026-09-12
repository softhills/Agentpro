<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only (FR-M12-05, SEC-12).
 *
 * Updates and deletes are blocked at the model rather than left to convention,
 * because the value of an audit log is precisely that it cannot be tidied up
 * after the fact.
 */
class AuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'before'     => 'array',
        'after'      => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('Audit events are immutable.');
        });

        static::deleting(function () {
            throw new \LogicException('Audit events cannot be deleted.');
        });
    }
}
