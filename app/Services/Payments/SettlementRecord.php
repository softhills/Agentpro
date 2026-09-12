<?php

namespace App\Services\Payments;

use Illuminate\Support\Carbon;

/**
 * One payout from the provider into the business bank account (FR-M11-06).
 *
 * All four money figures are carried, not just the one that hits the bank,
 * because the check that matters is whether the provider's own arithmetic holds:
 * total − fees − deductions should equal effective. Storing only `effective`
 * would make that unanswerable.
 */
final class SettlementRecord
{
    public function __construct(
        public readonly string $providerId,
        public readonly string $status,
        public readonly string $currency,
        /** All minor units. */
        public readonly int $totalMinor,
        public readonly int $feesMinor,
        public readonly int $deductionsMinor,
        public readonly int $effectiveMinor,
        public readonly ?Carbon $settlementDate = null,
        public readonly ?Carbon $createdAt = null,
    ) {}

    public function total(): float
    {
        return $this->totalMinor / 100;
    }

    public function fees(): float
    {
        return $this->feesMinor / 100;
    }

    public function deductions(): float
    {
        return $this->deductionsMinor / 100;
    }

    public function effective(): float
    {
        return $this->effectiveMinor / 100;
    }

    /** A settlement still in flight has nothing final to reconcile against. */
    public function isPaidOut(): bool
    {
        return in_array(strtolower($this->status), ['success', 'settled', 'paid'], true);
    }
}
