<?php

namespace App\Services\Payments;

use Illuminate\Support\Carbon;

/**
 * A single transaction inside a settlement (FR-M11-06).
 *
 * The reference is the hinge of the whole reconciliation: it is the value we
 * sent to the provider when the transaction was initialised, so it is the only
 * field guaranteed to point back at something of ours. A transaction whose
 * reference matches no order is the most important row reconciliation can
 * produce — somebody paid and the system does not know it.
 */
final class SettledTransaction
{
    public function __construct(
        public readonly string $providerId,
        public readonly ?string $reference,
        /** Minor units. */
        public readonly int $amountMinor,
        public readonly int $feesMinor = 0,
        public readonly ?string $channel = null,
        public readonly ?string $customerEmail = null,
        public readonly ?Carbon $paidAt = null,
    ) {}

    public function amount(): float
    {
        return $this->amountMinor / 100;
    }

    public function fees(): float
    {
        return $this->feesMinor / 100;
    }
}
