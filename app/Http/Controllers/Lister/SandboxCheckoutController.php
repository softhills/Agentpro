<?php

namespace App\Http\Controllers\Lister;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\Request;

/**
 * Stands in for Paystack's hosted form, in local development only.
 *
 * It does not mark anything paid. It shows the developer the exact signed
 * webhook body Paystack would send and the command to post it, so the real
 * sequence — pay, webhook, confirm — is what gets exercised locally rather than
 * a shortcut that only works in development.
 */
class SandboxCheckoutController extends Controller
{
    public function __invoke(Request $request, Order $order, PaymentGateway $gateway)
    {
        abort_unless(app()->environment('local', 'testing'), 404);
        abort_unless($order->user_id === $request->user()->id, 404);
        abort_unless($gateway instanceof FakeGateway, 404);

        $body = json_encode([
            'event' => 'charge.success',
            'data'  => [
                'id'        => random_int(1_000_000, 9_999_999),
                'reference' => $order->uuid,
                'status'    => 'success',
                'amount'    => (int) round((float) $order->amount * 100),
                'currency'  => $order->currency,
                'channel'   => 'bank_transfer',
            ],
        ], JSON_UNESCAPED_SLASHES);

        return view('scan.sandbox', [
            'order'     => $order,
            'body'      => $body,
            'signature' => $gateway->sign($body),
            'url'       => route('webhooks.paystack'),
        ]);
    }
}
