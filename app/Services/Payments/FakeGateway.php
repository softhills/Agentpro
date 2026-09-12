<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Refund;
use Illuminate\Support\Carbon;

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
 *
 * For refunds and settlement it imitates the provider's *timing*, not just its
 * shape: a refund comes back pending and only becomes processed later, and a
 * settlement covers a day that has already closed. Both are the properties that
 * make reconciliation necessary in the first place, so a fake that skipped them
 * would let code through that only works when money moves instantly.
 */
class FakeGateway implements PaymentGateway
{
    /** Paystack's local card pricing, which the synthetic fees imitate. */
    private const FEE_RATE = 0.015;

    private const FEE_CAP = 200000;     // ₦2,000, in kobo

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

    /**
     * Accepted, not completed — which is what a real provider says.
     *
     * A fake that returned "processed" here would let code ship that treats
     * submission as arrival, and the entire pending-refund half of the feature
     * would never be exercised locally.
     */
    public function refund(Order $order, float $amount, string $reason): RefundResult
    {
        return new RefundResult(
            providerId: 'fake_rf_'.substr(hash('sha256', $order->uuid.$amount.microtime()), 0, 16),
            status: 'pending',
            amountMinor: (int) round($amount * 100),
            currency: $order->currency,
            expectedAt: now()->addDays(3),
        );
    }

    /**
     * Completes a minute after submission, so the polling path can actually be
     * watched working in development instead of being taken on trust.
     */
    public function fetchRefund(string $providerRefundId): ?RefundResult
    {
        $refund = Refund::where('provider_refund_id', $providerRefundId)->first();

        if (! $refund) {
            return null;
        }

        $done = $refund->submitted_at !== null && $refund->submitted_at->addMinute()->isPast();

        return new RefundResult(
            providerId: $providerRefundId,
            status: $done ? 'processed' : 'pending',
            amountMinor: (int) round((float) $refund->amount * 100),
            currency: $refund->currency,
            expectedAt: $refund->expected_at ? Carbon::parse($refund->expected_at) : null,
        );
    }

    /**
     * Synthesised from orders that were actually paid, one settlement per day
     * of takings, paid out the following day.
     *
     * It reports what is in the database rather than inventing money, so the
     * reconciliation screen in development shows the real orders and a genuine
     * balance. It cannot manufacture the interesting failures — an unknown
     * reference, a short payout — and is not trying to: those are what the
     * tests are for.
     *
     * @return SettlementRecord[]
     */
    public function settlements(Carbon $from, Carbon $to): array
    {
        $out = [];

        foreach ($this->takingsByDay($from, $to) as $day => $orders) {
            $gross = 0;
            $fees  = 0;

            foreach ($orders as $order) {
                $minor = (int) round((float) $order->amount * 100);
                $gross += $minor;
                $fees  += $this->fee($minor);
            }

            $deductions = (int) round(
                (float) Refund::where('state', 'processed')
                    ->whereDate('processed_at', $day)
                    ->sum('amount') * 100
            );

            $out[] = new SettlementRecord(
                providerId: 'fake-'.str_replace('-', '', $day),
                status: 'success',
                currency: 'NGN',
                totalMinor: $gross,
                feesMinor: $fees,
                deductionsMinor: $deductions,
                effectiveMinor: $gross - $fees - $deductions,
                settlementDate: Carbon::parse($day)->addDay(),
                createdAt: Carbon::parse($day)->addDay()->setTime(9, 0),
            );
        }

        return $out;
    }

    /** @return SettledTransaction[] */
    public function settlementTransactions(string $providerSettlementId): array
    {
        $day = str_replace('fake-', '', $providerSettlementId);

        if (! preg_match('/^\d{8}$/', $day)) {
            return [];
        }

        $date = Carbon::createFromFormat('Ymd', $day)->startOfDay();

        return Order::where('state', '!=', 'pending')
            ->whereNotNull('paid_at')
            ->whereDate('paid_at', $date)
            ->get()
            ->map(function (Order $order) {
                $minor = (int) round((float) $order->amount * 100);

                return new SettledTransaction(
                    providerId: 'fake_tx_'.$order->id,
                    reference: $order->paystack_reference ?: $order->uuid,
                    amountMinor: $minor,
                    feesMinor: $this->fee($minor),
                    channel: $order->paystack_channel ?? 'bank_transfer',
                    customerEmail: $order->user?->email,
                    paidAt: $order->paid_at,
                );
            })
            ->all();
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

    private function fee(int $amountMinor): int
    {
        return (int) min(self::FEE_CAP, round($amountMinor * self::FEE_RATE));
    }

    /**
     * Only days that have already closed. A provider settles what it took
     * yesterday, never what it is taking right now, and pretending otherwise
     * would hide the window in which a paid order legitimately has no
     * settlement behind it.
     */
    private function takingsByDay(Carbon $from, Carbon $to): array
    {
        return Order::whereNotNull('paid_at')
            ->where('state', '!=', 'pending')
            ->whereDate('paid_at', '>=', $from->copy()->startOfDay())
            ->whereDate('paid_at', '<=', min($to->copy(), now()->subDay())->endOfDay())
            ->orderBy('paid_at')
            ->get()
            ->groupBy(fn (Order $order) => $order->paid_at->toDateString())
            ->all();
    }
}
