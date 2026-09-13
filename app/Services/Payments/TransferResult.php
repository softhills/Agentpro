<?php

namespace App\Services\Payments;

/**
 * What the provider says about an outbound transfer.
 *
 * `reversed` is separated from `failed` on purpose. Failed means it never left
 * and the money is still ours; reversed means it left, could not be delivered,
 * and came back days later. They look similar in an API response and mean
 * different things on a ledger — a reversal has to put money back that was
 * already spent, which nothing else in this system does.
 */
final class TransferResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $transferCode = null,
        public readonly ?string $reference = null,
        public readonly int $amountMinor = 0,
        public readonly ?string $failureReason = null,
    ) {}

    public function amount(): float
    {
        return $this->amountMinor / 100;
    }

    /** The money is in the recipient's account. */
    public function isPaid(): bool
    {
        return in_array(strtolower($this->status), ['success', 'successful'], true);
    }

    /** Rejected before leaving. */
    public function hasFailed(): bool
    {
        return in_array(strtolower($this->status), ['failed', 'abandoned'], true);
    }

    /** It left and came back. */
    public function wasReversed(): bool
    {
        return strtolower($this->status) === 'reversed';
    }

    /**
     * The provider is holding it for a one-time code.
     *
     * Paystack requires this per transfer unless the integration has it
     * disabled. It is not an error, but it is also not something this
     * application can satisfy on its own — nothing here can read an SMS — so it
     * has to be visible rather than treated as "pending".
     */
    public function needsOtp(): bool
    {
        return strtolower($this->status) === 'otp';
    }
}
