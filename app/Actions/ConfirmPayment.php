<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\PaymentEvent;
use App\Services\Payments\PaymentGateway;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turning a webhook into a paid order (FR-M4-04, SEC-05).
 *
 * Three rules, each of which exists because the obvious implementation gets it
 * wrong:
 *
 *  1. Confirmation happens here, from the webhook — never from the browser
 *     redirect. A payer who closes the tab has still paid; a payer who forges a
 *     redirect has not.
 *  2. The provider is asked what happened rather than believed. The webhook
 *     body says a payment succeeded; only fetchTransaction can confirm it, and
 *     the amount it reports is checked against the order.
 *  3. It is idempotent on the provider's event id. Providers retry, and a
 *     replayed event must not credit an order twice.
 */
class ConfirmPayment
{
    public function __construct(private PaymentGateway $gateway) {}

    /**
     * @return bool whether this call changed anything
     */
    public function fromWebhook(string $eventId, string $eventType, array $payload, bool $signatureValid): bool
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

        $reference = $payload['data']['reference'] ?? null;

        if (! $reference) {
            $event->update(['processed_at' => now()]);

            return false;
        }

        $order = Order::where('uuid', $reference)->first();

        if (! $order) {
            Log::warning('payment.webhook.unknown_order', ['reference' => $reference]);
            $event->update(['processed_at' => now()]);

            return false;
        }

        $event->update(['order_id' => $order->id]);

        if ($order->isPaid()) {
            $event->update(['processed_at' => now()]);

            return false;
        }

        // Rule 2: ask, do not believe.
        $status = $this->gateway->fetchTransaction($reference);

        if (! $status || ! $status->successful) {
            Log::warning('payment.webhook.not_successful_on_verify', ['reference' => $reference]);
            $event->update(['processed_at' => now()]);

            return false;
        }

        // A mismatch means either a tampered request or a pricing bug. Either
        // way the order is not marked paid on an amount we did not ask for.
        if (abs($status->amount() - (float) $order->amount) > 0.009) {
            Log::error('payment.amount_mismatch', [
                'reference' => $reference,
                'expected'  => (float) $order->amount,
                'received'  => $status->amount(),
            ]);
            $event->update(['processed_at' => now()]);

            return false;
        }

        DB::transaction(function () use ($order, $status, $event) {
            $order->update([
                'state'              => 'paid',
                'paystack_reference' => $status->reference,
                'paystack_channel'   => $status->channel,
                'paid_at'            => now(),
            ]);

            $event->update(['processed_at' => now()]);

            Audit::record('order.paid', $order, ['state' => 'pending'], [
                'state'     => 'paid',
                'channel'   => $status->channel,
                'reference' => $status->reference,
            ], $order->user_id);
        });

        return true;
    }
}
