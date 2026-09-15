@extends('layouts.app')
@section('title', 'Sandbox checkout — Agentpro')
@section('content')
<div class="container formwrap">
    <h1>Sandbox checkout</h1>
    <div class="alert alert-warn">
        <strong>Local development only</strong>
        <p style="margin:4px 0 0">
            This page stands in for Paystack's hosted form. It does not mark anything paid —
            payment is confirmed by webhook, so send the webhook below and the real
            sequence gets exercised.
        </p>
    </div>

    <section class="formsec">
        <h2>{{ $order->itemLabel() }} · {{ \App\Support\Money::naira($order->amount) }}</h2>
        <p class="secblurb">Reference {{ $order->uuid }}</p>

        <h3 class="titlegroup">Signed webhook body</h3>
        <pre class="codeblock">{{ $body }}</pre>

        <h3 class="titlegroup">Send it</h3>
        <pre class="codeblock">curl -X POST {{ $url }} \
  -H "Content-Type: application/json" \
  -H "x-paystack-signature: {{ $signature }}" \
  -d '{{ $body }}'</pre>

        <p class="fhint">
            Change a single byte of the body without re-signing and the webhook is rejected —
            which is the point.
        </p>

        <a href="{{ route('scan.schedule', $order) }}" class="btn btn-brand">I have sent it — continue</a>
    </section>
</div>
@endsection
