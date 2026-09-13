<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Refund;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

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

    // ------------------------------------------------------------- payouts

    /**
     * A handful of real Nigerian bank codes.
     *
     * Real ones rather than invented ones, so a developer typing a test account
     * is exercising the same code paths and string lengths production will.
     */
    public function banks(): array
    {
        return [
            '044' => 'Access Bank',
            '063' => 'Access Bank (Diamond)',
            '050' => 'Ecobank Nigeria',
            '070' => 'Fidelity Bank',
            '011' => 'First Bank of Nigeria',
            '214' => 'First City Monument Bank',
            '058' => 'Guaranty Trust Bank',
            '076' => 'Polaris Bank',
            '221' => 'Stanbic IBTC Bank',
            '232' => 'Sterling Bank',
            '032' => 'Union Bank of Nigeria',
            '033' => 'United Bank For Africa',
            '215' => 'Unity Bank',
            '035' => 'Wema Bank',
            '057' => 'Zenith Bank',
            '999992' => 'OPay',
            '999991' => 'PalmPay',
            '50211' => 'Kuda Bank',
            '100004' => 'Moniepoint',
        ];
    }

    /**
     * Resolves any well-formed NUBAN, and refuses everything else.
     *
     * It cannot know a real account holder, so it returns a stable made-up name
     * derived from the number — stable because the flow compares the resolved
     * name against the lister's identity, and a name that changed on every call
     * would make that check untestable.
     *
     * Refusing malformed numbers matters more than it looks: a development
     * driver that accepts anything hides the one bug that costs money here, an
     * unresolvable account saved as a payout destination.
     */
    public function resolveAccount(string $accountNumber, string $bankCode): ?ResolvedAccount
    {
        if (! preg_match('/^\d{10}$/', $accountNumber) || ! isset($this->banks()[$bankCode])) {
            return null;
        }

        // 0000000000 is reserved here as "the bank does not know this number",
        // so the refusal path can be exercised on demand.
        if ($accountNumber === '0000000000') {
            return null;
        }

        /*
         * Development convenience, and the only part of this class that reaches
         * for the session: it answers with the name of whoever is adding the
         * account, so the name-matching path can be walked through locally. A
         * real bank obviously does not do this, which is why anything that
         * depends on the resolved name stubs the gateway rather than trusting
         * what comes back here.
         */
        return new ResolvedAccount(
            accountNumber: $accountNumber,
            accountName: Str::upper(auth()->user()?->name ?? 'TEST ACCOUNT '.substr($accountNumber, -4)),
            bankCode: $bankCode,
            bankName: $this->banks()[$bankCode],
        );
    }

    public function createRecipient(string $accountNumber, string $bankCode, string $accountName): string
    {
        return 'RCP_fake_'.substr(hash('sha256', $bankCode.$accountNumber), 0, 16);
    }

    /**
     * Accepted, not delivered — as a real provider answers.
     *
     * Nigerian bank transfers are not instant and can be reversed days later, so
     * a fake that reported success here would let code ship that treats
     * submission as arrival, and the whole reversal path would never run
     * locally.
     */
    public function transfer(string $recipientCode, float $amount, string $reference, string $reason): TransferResult
    {
        if ($amount > ($this->balance() ?? 0)) {
            throw new RuntimeException('Insufficient balance on the integration.');
        }

        return new TransferResult(
            status: 'pending',
            transferCode: 'TRF_fake_'.substr(hash('sha256', $reference), 0, 16),
            reference: $reference,
            amountMinor: (int) round($amount * 100),
        );
    }

    /** Completes a minute after submission, so the polling path can be watched. */
    public function fetchTransfer(string $transferCodeOrReference): ?TransferResult
    {
        $payout = \App\Models\Payout::where('provider_transfer_code', $transferCodeOrReference)
            ->orWhere('provider_reference', $transferCodeOrReference)
            ->first();

        if (! $payout) {
            return null;
        }

        $done = $payout->submitted_at !== null && $payout->submitted_at->addMinute()->isPast();

        return new TransferResult(
            status: $done ? 'success' : 'pending',
            transferCode: $payout->provider_transfer_code,
            reference: $payout->provider_reference,
            amountMinor: (int) round((float) $payout->amount * 100),
        );
    }

    /**
     * Whatever has settled, less what has already been paid out.
     *
     * Derived from the database rather than invented, for the same reason the
     * settlements are: so the balance shown in development is one a developer
     * can reason about, and so "you cannot pay out more than you have" is a
     * constraint that actually bites locally.
     */
    public function balance(): ?float
    {
        $in = (float) Order::whereIn('state', ['paid', 'partially_refunded'])->sum('amount')
            - (float) Order::sum('refunded_amount');

        $out = (float) \App\Models\Payout::whereIn('state', ['submitted', 'paid'])->sum('amount');

        return max(0, round($in - $out, 2));
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
