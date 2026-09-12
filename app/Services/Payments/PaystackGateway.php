<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Paystack (FR-M11-01).
 *
 * Amounts cross the wire in kobo. Converting in exactly one place, here, avoids
 * the classic hundred-fold error in both directions.
 */
class PaystackGateway implements PaymentGateway
{
    public function __construct(
        private string $secretKey,
        private string $baseUrl = 'https://api.paystack.co',
    ) {
        if ($secretKey === '') {
            throw new RuntimeException('Paystack secret key is not configured.');
        }
    }

    public function initialise(Order $order, string $callbackUrl): Checkout
    {
        $response = $this->client()->post('/transaction/initialize', [
            'email'        => $order->user->email,
            // Kobo. The order's amount came from server-side configuration.
            'amount'       => (int) round((float) $order->amount * 100),
            'currency'     => $order->currency,
            'reference'    => $order->uuid,
            'callback_url' => $callbackUrl,
            // FR-M11-01: bank transfer matters disproportionately in this market.
            'channels'     => ['card', 'bank_transfer', 'ussd', 'bank'],
            'metadata'     => [
                'order_id'    => $order->id,
                'item_type'   => $order->item_type,
                'property_id' => $order->property_id,
            ],
        ]);

        if (! $response->successful() || ! ($response['status'] ?? false)) {
            Log::error('paystack.initialise_failed', [
                'order_id' => $order->id,
                'status'   => $response->status(),
            ]);

            throw new RuntimeException('Could not start the payment. Try again shortly.');
        }

        return new Checkout(
            reference: $response['data']['reference'],
            redirectUrl: $response['data']['authorization_url'],
        );
    }

    /**
     * SEC-05. HMAC-SHA512 of the raw body, keyed with the secret, compared in
     * constant time. An unsigned or mis-signed body is not a payment
     * notification, whatever it claims to be.
     */
    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        return hash_equals(
            hash_hmac('sha512', $rawBody, $this->secretKey),
            $signature
        );
    }

    public function fetchTransaction(string $reference): ?TransactionStatus
    {
        $response = $this->client()->get('/transaction/verify/'.urlencode($reference));

        if (! $response->successful() || ! ($response['status'] ?? false)) {
            return null;
        }

        $data = $response['data'];

        return new TransactionStatus(
            reference: $data['reference'],
            successful: ($data['status'] ?? null) === 'success',
            amountMinor: (int) ($data['amount'] ?? 0),
            currency: $data['currency'] ?? 'NGN',
            channel: $data['channel'] ?? null,
        );
    }

    public function name(): string
    {
        return 'paystack';
    }

    private function client()
    {
        return Http::withToken($this->secretKey)
            ->baseUrl($this->baseUrl)
            ->timeout(20)
            ->retry(2, 200);
    }
}
