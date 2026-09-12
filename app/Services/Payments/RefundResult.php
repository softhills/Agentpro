<?php

namespace App\Services\Payments;

use Illuminate\Support\Carbon;

/**
 * What the provider says about a refund (FR-M11-05).
 *
 * `status` is the provider's own word, kept verbatim rather than mapped on the
 * way in. Mapping at the boundary throws away the distinction between "queued"
 * and "sent to the bank", which is exactly the distinction someone chasing a
 * refund three days later needs.
 */
final class RefundResult
{
    public function __construct(
        public readonly string $providerId,
        public readonly string $status,
        /** Minor units, as the provider reports them. */
        public readonly int $amountMinor,
        public readonly string $currency = 'NGN',
        public readonly ?Carbon $expectedAt = null,
        public readonly ?string $failureReason = null,
    ) {}

    public function amount(): float
    {
        return $this->amountMinor / 100;
    }

    /** The money has reached the payer. */
    public function isProcessed(): bool
    {
        return in_array(strtolower($this->status), ['processed', 'success', 'successful'], true);
    }

    public function hasFailed(): bool
    {
        return in_array(strtolower($this->status), ['failed', 'reversed', 'cancelled'], true);
    }
}
