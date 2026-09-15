<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link between an Agentpro account and an identity at a provider (FR-M1-02).
 *
 * `provider_user_id` is the provider's stable subject id, and it is the only
 * thing a sign-in is matched on. Matching on email instead would follow the
 * address rather than the person: somebody who changes the address on their
 * Google account would come back as a stranger, and whoever inherits the old
 * address would arrive as them.
 */
class SocialAccount extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['linked_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
