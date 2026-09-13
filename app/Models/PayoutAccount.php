<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a lister's money goes (FR-M11-07).
 *
 * The name on this row came from the bank, not from the person who added it.
 * Everything displays that name, because the typed one is a claim and this is
 * the only fact available about who owns the number.
 */
class PayoutAccount extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'resolved_at'           => 'datetime',
        'usable_from'           => 'datetime',
        'approved_at'           => 'datetime',
        'is_active'             => 'boolean',
        'name_matches_identity' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payouts()
    {
        return $this->hasMany(Payout::class);
    }

    /** Account numbers are not secrets, but there is no reason to print one in full. */
    public function masked(): string
    {
        return str_repeat('•', max(0, strlen($this->account_number) - 4))
            .substr($this->account_number, -4);
    }

    /** Has the account-change hold expired? */
    public function isOutOfCooldown(): bool
    {
        return $this->usable_from === null || $this->usable_from->isPast();
    }

    /**
     * Can money be sent here right now?
     *
     * Three conditions, and the interesting one is the middle: an account whose
     * bank name does not match the verified identity needs a person to agree,
     * because in this market that is as likely to be a legitimate business
     * account as it is to be a redirected payment.
     */
    public function isPayable(): bool
    {
        return $this->is_active
            && $this->isOutOfCooldown()
            && ($this->name_matches_identity || $this->approved_at !== null);
    }

    /** Why it is not payable, in words an operator or lister can act on. */
    public function blocker(): ?string
    {
        return match (true) {
            ! $this->is_active        => 'This account has been replaced.',
            ! $this->isOutOfCooldown() => 'New bank details are held until '
                .$this->usable_from->format('j M, g:ia').'. This is a security hold, not a review.',
            ! $this->name_matches_identity && $this->approved_at === null => 'The name on this account does not match your verified identity, so it needs checking by hand first.',
            default => null,
        };
    }
}
