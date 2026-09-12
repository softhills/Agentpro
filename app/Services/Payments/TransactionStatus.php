<?php

namespace App\Services\Payments;

final class TransactionStatus
{
    public function __construct(
        public readonly string $reference,
        public readonly bool $successful,
        /** Minor units, as the provider reports them — kobo for NGN. */
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?string $channel = null,
    ) {}

    /** Naira, for comparison against the order. */
    public function amount(): float
    {
        return $this->amountMinor / 100;
    }
}
