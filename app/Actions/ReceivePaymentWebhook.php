<?php

namespace App\Actions;

use App\Models\PaymentEvent;
use App\Models\Payout;
use App\Models\Refund;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\RefundResult;
use App\Services\Payments\TransferResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The front door for every provider callback (SEC-05).
 *
 * One job that nothing below it repeats: record the delivery, refuse to act on
 * an unsigned one, act on each delivery exactly once, and then hand it to
 * whatever actually understands that kind of event.
 *
 * Routing is the reason this exists. Before refunds there was only one kind of
 * event, so "the webhook" and "confirming a payment" were the same thing. They
 * are not: a `refund.processed` delivery carries no transaction reference at
 * the top level, so payment confirmation quietly recorded it as unusable and
 * moved on — the refund would have stayed "with the provider" forever while the
 * money had in fact reached the customer.
 */
class ReceivePaymentWebhook
{
    public function __construct(
        private PaymentGateway $gateway,
        private ConfirmPayment $payments,
        private IssueRefund $refunds,
        private IssuePayout $payouts,
    ) {}

    /**
     * @return bool whether this delivery changed anything
     */
    public function handle(string $eventId, string $eventType, array $payload, bool $signatureValid): bool
    {
        // Recorded whether or not it is valid: a stream of rejected signatures
        // is exactly the thing worth being able to see afterwards.
        $event = PaymentEvent::firstOrCreate(
            ['event_id' => $eventId],
            [
                'provider'        => $this->gateway->name(),
                'event_type'      => $eventType,
                'payload'         => $payload,
                'signature_valid' => $signatureValid,
            ]
        );

        if (! $signatureValid) {
            Log::warning('payment.webhook.bad_signature', ['event_id' => $eventId]);

            return false;
        }

        // Already handled — a retry, which is normal and must be a no-op.
        if ($event->processed_at !== null) {
            return false;
        }

        return match (true) {
            str_starts_with($eventType, 'refund.')   => $this->routeRefund($event, $eventType, $payload),
            str_starts_with($eventType, 'transfer.') => $this->routeTransfer($event, $eventType, $payload),
            default                                  => $this->routeCharge($event, $payload),
        };
    }

    private function routeCharge(PaymentEvent $event, array $payload): bool
    {
        $reference = $payload['data']['reference'] ?? null;

        if (! $reference) {
            $event->update(['processed_at' => now()]);

            return false;
        }

        return $this->payments->confirm($reference, $event);
    }

    /**
     * Refund outcomes (FR-M11-05).
     *
     * Matched on the provider's refund id where there is one, falling back to
     * the transaction reference. The fallback matters: Paystack's refund
     * webhooks identify the transaction reliably and the refund itself less so,
     * and a refund whose outcome cannot be matched back is a customer who has
     * been paid while our records say they are still waiting.
     */
    private function routeRefund(PaymentEvent $event, string $eventType, array $payload): bool
    {
        $data = $payload['data'] ?? [];

        $refund = $this->findRefund($data);

        if (! $refund) {
            Log::warning('payment.webhook.unmatched_refund', [
                'event'     => $eventType,
                'reference' => $data['transaction_reference'] ?? null,
            ]);
            $event->update(['processed_at' => now()]);

            return false;
        }

        $event->update(['order_id' => $refund->order_id, 'processed_at' => now()]);

        $status = (string) ($data['status'] ?? match ($eventType) {
            'refund.processed' => 'processed',
            'refund.failed'    => 'failed',
            default            => 'pending',
        });

        return $this->refunds->settle($refund, new RefundResult(
            providerId: (string) ($data['id'] ?? $refund->provider_refund_id ?? ''),
            status: $status,
            amountMinor: (int) ($data['amount'] ?? round((float) $refund->amount * 100)),
            currency: (string) ($data['currency'] ?? $refund->currency),
            expectedAt: isset($data['expected_at']) ? Carbon::parse($data['expected_at']) : null,
            failureReason: $data['refund_note'] ?? null,
        ));
    }

    /**
     * Outbound transfer outcomes (FR-M11-07).
     *
     * Matched on our own reference rather than the provider's transfer code:
     * the reference is minted before the transfer is attempted, so it exists
     * even for a transfer whose creation response never reached us — which is
     * exactly the case where knowing the outcome matters most.
     */
    private function routeTransfer(PaymentEvent $event, string $eventType, array $payload): bool
    {
        $data = $payload['data'] ?? [];

        $payout = Payout::where('provider_reference', $data['reference'] ?? '')
            ->orWhere('provider_transfer_code', $data['transfer_code'] ?? '')
            ->first();

        if (! $payout) {
            Log::warning('payment.webhook.unmatched_transfer', [
                'event'     => $eventType,
                'reference' => $data['reference'] ?? null,
            ]);
            $event->update(['processed_at' => now()]);

            return false;
        }

        $event->update(['processed_at' => now()]);

        $status = (string) ($data['status'] ?? match ($eventType) {
            'transfer.success'  => 'success',
            'transfer.failed'   => 'failed',
            'transfer.reversed' => 'reversed',
            default             => 'pending',
        });

        return $this->payouts->settle($payout, new TransferResult(
            status: $status,
            transferCode: $data['transfer_code'] ?? $payout->provider_transfer_code,
            reference: $data['reference'] ?? $payout->provider_reference,
            amountMinor: (int) ($data['amount'] ?? round((float) $payout->amount * 100)),
            failureReason: $data['reason'] ?? ($data['message'] ?? null),
        ));
    }

    private function findRefund(array $data): ?Refund
    {
        if (! empty($data['id'])) {
            $byId = Refund::where('provider_refund_id', (string) $data['id'])->first();

            if ($byId) {
                return $byId;
            }
        }

        $reference = $data['transaction_reference']
            ?? $data['transaction']['reference']
            ?? $data['reference']
            ?? null;

        if (! $reference) {
            return null;
        }

        // Oldest unfinished refund on that order. With several in flight the
        // provider's id is the only thing that distinguishes them, so this is a
        // best effort and deliberately never touches a settled one.
        return Refund::whereIn('state', ['submitted', 'requested'])
            ->whereHas('order', fn ($q) => $q->where('uuid', $reference)->orWhere('paystack_reference', $reference))
            ->oldest('submitted_at')
            ->first();
    }
}
