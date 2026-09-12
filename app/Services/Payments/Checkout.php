<?php

namespace App\Services\Payments;

final class Checkout
{
    public function __construct(
        public readonly string $reference,
        public readonly string $redirectUrl,
    ) {}
}
