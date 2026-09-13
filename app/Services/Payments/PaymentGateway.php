<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Payment provider (M11).
 *
 * Paystack is the chosen provider, but the application talks to this interface
 * so that the things worth protecting — that an amount is never taken from the
 * client, that a payment is confirmed by webhook rather than by a browser
 * redirect, and that what the bank received is checked rather than assumed —
 * are properties of our code rather than of an SDK.
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

    /**
     * Send a refund (FR-M11-05).
     *
     * Takes the transaction reference rather than a destination account: a
     * refund returns money the way it arrived, to whoever sent it. That is what
     * makes giving the application this authority acceptable at all — the worst
     * a stolen session can do is give money back to the people who paid it, not
     * move it somewhere new.
     *
     * Returns what the provider accepted. It is almost never "processed" yet.
     */
    public function refund(Order $order, float $amount, string $reason): RefundResult;

    /** Poll one refund, for when the webhook never arrives. */
    public function fetchRefund(string $providerRefundId): ?RefundResult;

    /**
     * Payouts into the business bank account, over a date window (FR-M11-06).
     *
     * A window rather than a day because providers backfill and amend: a
     * settlement dated Monday can change on Wednesday, and a job that only ever
     * looks at yesterday never sees the amendment.
     *
     * @return SettlementRecord[]
     */
    public function settlements(Carbon $from, Carbon $to): array;

    /**
     * The transactions that make up one settlement.
     *
     * Implementations must follow the provider's paging to the end. A partial
     * list here does not look like an error downstream — it looks like missing
     * money, which is worse.
     *
     * @return SettledTransaction[]
     */
    public function settlementTransactions(string $providerSettlementId): array;

    /**
     * Nigerian banks and their codes, for the account form.
     *
     * @return array<string,string> code => name
     */
    public function banks(): array;

    /**
     * Ask the bank who owns an account number (FR-M11-07).
     *
     * The one call that makes a payout destination checkable. Returns null when
     * the bank does not recognise the number, which must be treated as "do not
     * save this account" rather than as a transient error.
     */
    public function resolveAccount(string $accountNumber, string $bankCode): ?ResolvedAccount;

    /** Register a destination with the provider; returns its handle. */
    public function createRecipient(string $accountNumber, string $bankCode, string $accountName): string;

    /**
     * Send money out.
     *
     * `$reference` is ours and must be unique per payout: it is the only thing
     * standing between a retried submission and a second transfer.
     */
    public function transfer(string $recipientCode, float $amount, string $reference, string $reason): TransferResult;

    public function fetchTransfer(string $transferCodeOrReference): ?TransferResult;

    /**
     * Spendable balance, in naira, or null if it cannot be read.
     *
     * Checked before sending. A transfer against an empty float fails at the
     * provider anyway, but it fails after the payout has been recorded as sent,
     * which is a worse place to find out.
     */
    public function balance(): ?float;

    public function name(): string;
}
