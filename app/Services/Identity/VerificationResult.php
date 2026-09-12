<?php

namespace App\Services\Identity;

final class VerificationResult
{
    private function __construct(
        public readonly string $state,       // verified | pending | rejected
        public readonly ?string $reference,
        public readonly ?string $reason = null,
    ) {}

    public static function verified(string $reference): self
    {
        return new self('verified', $reference);
    }

    public static function pending(string $reference): self
    {
        return new self('pending', $reference);
    }

    public static function rejected(?string $reference, string $reason): self
    {
        return new self('rejected', $reference, $reason);
    }
}
