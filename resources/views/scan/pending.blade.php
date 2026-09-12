@extends('layouts.app')
@section('title', 'Confirming your payment — Agentpro')
@section('content')
<div class="authwrap">
    <div class="authcard">
        <h1>Confirming your payment</h1>
        {{-- FR-M4-04: the browser returning is not proof of payment. This page
             exists precisely because we will not treat it as such. --}}
        <p class="authlede">
            We are waiting for confirmation from the payment provider. This usually takes
            a few seconds, and bank transfers can take a little longer. You do not need to
            stay on this page — we will email you, and the capture will be waiting in your
            dashboard.
        </p>
        <div class="statebox state-pending">
            <span class="statedot"></span>
            <div>
                <strong>{{ $order->itemLabel() }} · {{ \App\Support\Money::naira($order->amount) }}</strong>
                <p>Reference {{ $order->uuid }}</p>
            </div>
        </div>
        <a href="{{ route('scan.schedule', $order) }}" class="btn btn-blue btn-block">Check again</a>
        <p class="authalt"><a href="{{ route('lister.dashboard') }}">Back to your listings</a></p>
    </div>
</div>
@endsection
