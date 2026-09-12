<?php

namespace App\Support;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

final class Audit
{
    /**
     * Record a lifecycle, moderation, verification or payment event.
     *
     * Called from the action that performs the change, never from a controller,
     * so an event cannot be skipped by reaching the same change another way.
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        array $before = [],
        array $after = [],
        ?int $actorId = null,
    ): void {
        AuditEvent::create([
            'actor_id'     => $actorId ?? Auth::id(),
            'action'       => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id'   => $subject?->getKey(),
            'before'       => $before ?: null,
            'after'        => $after ?: null,
            'ip'           => Request::ip(),
            'created_at'   => now(),
        ]);
    }
}
