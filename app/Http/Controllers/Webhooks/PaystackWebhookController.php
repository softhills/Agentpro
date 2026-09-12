<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\ReceivePaymentWebhook;
use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Paystack webhook (FR-M4-04, SEC-05).
 *
 * Always answers 200, even for a rejected signature. A payment provider reads a
 * non-2xx as "retry", so returning 4xx for a forged request buys nothing and
 * returning 5xx for a body we have already stored causes duplicate deliveries
 * of something we handled. The decision about whether anything happened is
 * recorded, not signalled in the status code.
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentGateway $gateway, ReceivePaymentWebhook $receive)
    {
        // The raw body, not the parsed array: re-encoding changes the bytes and
        // would invalidate a perfectly good signature.
        $raw = $request->getContent();

        $valid = $gateway->verifySignature($raw, $request->header('x-paystack-signature'));

        $payload = json_decode($raw, true) ?: [];

        $receive->handle(
            // Paystack does not always send an event id, so fall back to a
            // deterministic hash of the body — a genuine retry carries the same
            // bytes and therefore the same key, which is what idempotency needs.
            eventId: $payload['id'] ?? ($payload['data']['id'] ?? null)
                ? (string) ($payload['id'] ?? $payload['data']['id'])
                : Str::of($raw)->pipe(fn ($b) => hash('sha256', (string) $b)),
            eventType: $payload['event'] ?? 'unknown',
            payload: $payload,
            signatureValid: $valid,
        );

        // 200 with a token body rather than 204: providers treat any non-2xx as
        // "retry", and an explicit acknowledgement is easier to read in their
        // delivery log than an empty response.
        return response()->json(['received' => true]);
    }
}
