<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Support\Carbon;
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

    /**
     * FR-M11-05.
     *
     * Identified by the transaction reference, which is the order's uuid — so a
     * refund cannot be aimed anywhere except back at the original payer.
     *
     * Throws rather than returning null on failure. A refund that quietly did
     * not happen is the one outcome the caller must not be able to miss: it
     * would be recorded as sent, deducted from the outstanding balance, and
     * nobody would find out until the customer complained.
     */
    public function refund(Order $order, float $amount, string $reason): RefundResult
    {
        $reference = $order->paystack_reference ?: $order->uuid;

        $response = $this->client()->post('/refund', [
            'transaction'   => $reference,
            'amount'        => (int) round($amount * 100),
            'currency'      => $order->currency,
            // Paystack shows this to us; the customer note reaches the payer.
            'merchant_note' => $reason,
            'customer_note' => 'Refund for '.$order->itemLabel().' on Agentpro',
        ]);

        if (! $response->successful() || ! ($response['status'] ?? false)) {
            Log::error('paystack.refund_failed', [
                'order_id' => $order->id,
                'status'   => $response->status(),
                'body'     => $response->json('message'),
            ]);

            throw new RuntimeException(
                'Paystack refused the refund: '.($response->json('message') ?: 'no reason given')
            );
        }

        return $this->toRefund($response['data']);
    }

    public function fetchRefund(string $providerRefundId): ?RefundResult
    {
        $response = $this->client()->get('/refund/'.urlencode($providerRefundId));

        if (! $response->successful() || ! ($response['status'] ?? false)) {
            return null;
        }

        return $this->toRefund($response['data']);
    }

    /** @return SettlementRecord[] */
    public function settlements(Carbon $from, Carbon $to): array
    {
        $out = [];

        foreach ($this->paged('/settlement', [
            'from' => $from->toDateString(),
            'to'   => $to->toDateString(),
        ]) as $row) {
            $out[] = new SettlementRecord(
                providerId: (string) $row['id'],
                status: (string) ($row['status'] ?? 'pending'),
                currency: (string) ($row['currency'] ?? 'NGN'),
                totalMinor: (int) ($row['total_amount'] ?? 0),
                feesMinor: (int) ($row['total_fees'] ?? 0),
                deductionsMinor: (int) ($row['deductions'] ?? 0),
                effectiveMinor: (int) ($row['effective_amount'] ?? 0),
                settlementDate: isset($row['settlement_date']) ? Carbon::parse($row['settlement_date']) : null,
                createdAt: isset($row['createdAt']) ? Carbon::parse($row['createdAt']) : null,
            );
        }

        return $out;
    }

    /** @return SettledTransaction[] */
    public function settlementTransactions(string $providerSettlementId): array
    {
        $out = [];

        foreach ($this->paged('/settlement/'.urlencode($providerSettlementId).'/transaction') as $row) {
            $out[] = new SettledTransaction(
                providerId: (string) $row['id'],
                reference: $row['reference'] ?? null,
                amountMinor: (int) ($row['amount'] ?? 0),
                feesMinor: (int) ($row['fees'] ?? 0),
                channel: $row['channel'] ?? null,
                customerEmail: $row['customer']['email'] ?? null,
                paidAt: isset($row['paid_at']) ? Carbon::parse($row['paid_at']) : null,
            );
        }

        return $out;
    }

    public function name(): string
    {
        return 'paystack';
    }

    private function toRefund(array $data): RefundResult
    {
        return new RefundResult(
            providerId: (string) ($data['id'] ?? ''),
            status: (string) ($data['status'] ?? 'pending'),
            amountMinor: (int) ($data['amount'] ?? 0),
            currency: (string) ($data['currency'] ?? 'NGN'),
            expectedAt: isset($data['expected_at']) ? Carbon::parse($data['expected_at']) : null,
            failureReason: $data['refund_note'] ?? null,
        );
    }

    /**
     * Walk a paged list endpoint to the end.
     *
     * Stopping at the first page is the failure mode this exists to prevent:
     * downstream it does not look like a broken API call, it looks like a
     * settlement that is missing money, and the reconciliation report would
     * confidently say so.
     */
    private function paged(string $path, array $query = [], int $perPage = 100): array
    {
        $rows = [];
        $page = 1;

        do {
            $response = $this->client()->get($path, $query + ['perPage' => $perPage, 'page' => $page]);

            if (! $response->successful() || ! ($response['status'] ?? false)) {
                throw new RuntimeException(
                    'Paystack returned '.$response->status().' for '.$path.' page '.$page
                    .'. Refusing to reconcile against a partial list.'
                );
            }

            $batch = $response['data'] ?? [];
            $rows = array_merge($rows, $batch);

            $pageCount = (int) ($response['meta']['pageCount'] ?? 1);
            $page++;
        } while ($batch !== [] && $page <= $pageCount);

        return $rows;
    }

    private function client()
    {
        return Http::withToken($this->secretKey)
            ->baseUrl($this->baseUrl)
            ->timeout(20)
            ->retry(2, 200);
    }
}
