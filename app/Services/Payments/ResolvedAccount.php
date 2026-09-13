<?php

namespace App\Services\Payments;

/**
 * What the bank says a number belongs to.
 *
 * The point of this object is that the name inside it did not come from the
 * person adding the account. Everything downstream displays this name, not the
 * typed one, because a typed name is a claim and this is a fact.
 */
final class ResolvedAccount
{
    public function __construct(
        public readonly string $accountNumber,
        public readonly string $accountName,
        public readonly string $bankCode,
        public readonly string $bankName,
    ) {}
}
