<?php

namespace App\Services\Messaging;

final class SmsDelivery
{
    public function __construct(
        public readonly bool $accepted,
        public readonly ?string $providerId = null,
        public readonly ?string $error = null,
        /**
         * What the gateway says it charged, where it says so at all. Kept
         * nullable rather than defaulted to zero: "we do not know" and "it was
         * free" are different answers, and a bill reconciled against a column
         * of zeros would look fine.
         */
        public readonly ?float $cost = null,
    ) {}

    public static function sent(?string $providerId = null, ?float $cost = null): self
    {
        return new self(accepted: true, providerId: $providerId, cost: $cost);
    }

    public static function failed(string $error): self
    {
        return new self(accepted: false, error: $error);
    }
}
