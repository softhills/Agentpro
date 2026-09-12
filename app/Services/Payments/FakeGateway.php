<?php

namespace App\Services\Payments;

use App\Models\Order;

/**
 * Development driver. Never bind this outside local and testing.
 *
 * It signs with the same HMAC-SHA512 scheme Paystack uses, so the webhook
 * signature check is exercised for real in development rather than being
 * bypassed — the one part of the payment path where a bug is silent until it is
 * expensive.
 *
 * It deliberately does not mark anything paid on its own. Payment is confirmed
 * by webhook (FR-M4-04), so the developer has to send one, which keeps the real
 * sequence honest locally.
 */
class FakeGateway implements PaymentGateway
{
    public function __construct(private string $secretKey = 'fake_secret') {}

    public function initialise(Order $order, string $callbackUrl): Checkout
    {
        return new Checkout(
            reference: $order->uuid,
            // Lands on a local page that stands in for the hosted form.
            redirectUrl: route('scan.sandbox', ['order' => $order->uuid]),
        );
    }

    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $rawBody, $this->secretKey), $signature);
    }

    /** Reports success at the order's own amount, so tampering tests still bite. */
    public function fetchTransaction(string $reference): ?TransactionStatus
    {
        $order = Order::where('uuid', $reference)->first();

        if (! $order) {
            return null;
        }

        return new TransactionStatus(
            reference: $reference,
            successful: true,
            amountMinor: (int) round((float) $order->amount * 100),
            currency: $order->currency,
            channel: 'bank_transfer',
        );
    }

    public function name(): string
    {
        return 'fake';
    }

    /** Helper for tests and the local sandbox page. */
    public function sign(string $rawBody): string
    {
        return hash_hmac('sha512', $rawBody, $this->secretKey);
    }
}
