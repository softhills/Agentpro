<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\PaymentEvent;
use App\Services\Payments\PaymentGateway;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turning a provider reference into a paid order (FR-M4-04, SEC-05).
 *
 * Two rules, each of which exists because the obvious implementation gets it
 * wrong:
 *
 *  1. The provider is asked what happened rather than believed. A webhook body
 *     says a payment succeeded; only fetchTransaction can confirm it, and the
 *     amount it reports is checked against the order.
 *  2. It is safe to call twice. Providers retry, and reconciliation can arrive
 *     at the same conclusion a day later from a different direction, so a
 *     second call on a paid order must change nothing.
 *
 * Idempotency on the *delivery* — the provider's event id — belongs one level
 * up, in ReceivePaymentWebhook. This class is about the order.
 */
class ConfirmPayment
{
    public function __construct(private PaymentGateway $gateway) {}

    /**
     * @param  PaymentEvent|null  $event  the delivery this came from, if any
     * @param  int|null  $actorId  the operator, when a human confirmed it from
     *                             reconciliation rather than a webhook
     * @return bool whether this call changed anything
     */
    public function confirm(string $reference, ?PaymentEvent $event = null, ?int $actorId = null): bool
    {
        $order = Order::where('uuid', $reference)
            ->orWhere('paystack_reference', $reference)
            ->first();

        if (! $order) {
            Log::warning('payment.unknown_order', ['reference' => $reference]);
            $event?->update(['processed_at' => now()]);

            return false;
        }

        $event?->update(['order_id' => $order->id]);

        if ($order->isPaid()) {
            $event?->update(['processed_at' => now()]);

            return false;
        }

        // Rule 1: ask, do not believe.
        $status = $this->gateway->fetchTransaction($reference);

        if (! $status || ! $status->successful) {
            Log::warning('payment.not_successful_on_verify', ['reference' => $reference]);
            $event?->update(['processed_at' => now()]);

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
            $event?->update(['processed_at' => now()]);

            return false;
        }

        DB::transaction(function () use ($order, $status, $event, $actorId) {
            $order->update([
                'state'              => 'paid',
                'paystack_reference' => $status->reference,
                'paystack_channel'   => $status->channel,
                'paid_at'            => now(),
            ]);

            $event?->update(['processed_at' => now()]);

            Audit::record('order.paid', $order, ['state' => 'pending'], [
                'state'     => 'paid',
                'channel'   => $status->channel,
                'reference' => $status->reference,
                // Worth distinguishing in the log: a payment recovered from a
                // settlement is a webhook that never arrived, which is a
                // different problem from an ordinary sale.
                'source'    => $event ? 'webhook' : 'reconciliation',
            ], $actorId ?? $order->user_id);
        });

        return true;
    }
}
