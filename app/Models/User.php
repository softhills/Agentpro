<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * Deliberately narrow. verification_state, is_staff and staff_role are NOT
     * mass assignable — they are privilege, and a mass-assignment slip on any of
     * them is a privilege escalation, not a bug in a form (SEC-03).
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'email',
        'password',
        'category',
        'phone',
        'notification_preferences',
        'commute_label',
        'commute_lat',
        'commute_lng',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'verification_reference',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'        => 'datetime',
            'phone_verified_at'        => 'datetime',
            'verified_at'              => 'datetime',
            'password'                 => 'hashed',
            'is_staff'                 => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'lister_id');
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    // ------------------------------------------------------------------- state

    /**
     * FR-M1-05: the gate on publishing. Checked in the policy layer, not only in
     * the UI — an unverified lister must not be able to submit by replaying a
     * form post.
     */
    public function isVerified(): bool
    {
        return $this->verification_state === 'verified';
    }

    public function isSuspended(): bool
    {
        return $this->verification_state === 'suspended';
    }

    /**
     * FR-M1-03: browsing needs no account, but saving, rating, reporting,
     * feedback and contact all do. Listing requires a category beyond 'seeker'.
     */
    public function canList(): bool
    {
        return $this->category !== 'seeker' && ! $this->isSuspended();
    }

    public function isStaff(?string $role = null): bool
    {
        if (! $this->is_staff) {
            return false;
        }

        return $role === null || $this->staff_role === $role || $this->staff_role === 'admin';
    }

    public function payoutAccounts(): HasMany
    {
        return $this->hasMany(PayoutAccount::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** The one destination money may currently be sent to, if any. */
    public function activePayoutAccount(): ?PayoutAccount
    {
        return $this->payoutAccounts()->where('is_active', true)->latest('id')->first();
    }

    /** Human label for the account category. */
    public function categoryLabel(): string
    {
        return match ($this->category) {
            'independent_agent' => 'Independent agent',
            'property_owner'    => 'Property owner',
            'sellers_agent'     => "Seller's agent",
            'developer'         => 'Developer',
            'brokerage_firm'    => 'Brokerage firm',
            default             => 'Seeker',
        };
    }

    public function initials(): string
    {
        return collect(explode(' ', $this->name))
            ->take(2)
            ->map(fn ($word) => mb_substr($word, 0, 1))
            ->implode('');
    }
}
