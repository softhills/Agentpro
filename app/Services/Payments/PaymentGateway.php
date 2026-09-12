<?php

namespace App\Services\Payments;

use App\Models\Order;

/**
 * Payment provider (M11).
 *
 * Paystack is the chosen provider, but the application talks to this interface
 * so that the two things worth protecting — that an amount is never taken from
 * the client, and that a payment is confirmed by webhook rather than by a
 * browser redirect — are properties of our code rather than of an SDK.
 */
interface PaymentGateway
{
    /**
     * Begin a transaction and return where to send the payer.
     * The amount comes from the order, which got it from server-side config.
     */
    public function initialise(Order $order, string $callbackUrl): Checkout;

    /**
     * Is this webhook body genuinely from the provider?
     *
     * Takes the raw request body, not the parsed array: any re-encoding changes
     * the bytes and invalidates the signature.
     */
    public function verifySignature(string $rawBody, ?string $signature): bool;

    /**
     * Ask the provider what actually happened, rather than believing the
     * webhook payload. The amount returned here is what gets checked against
     * the order.
     */
    public function fetchTransaction(string $reference): ?TransactionStatus;

    public function name(): string;
}
