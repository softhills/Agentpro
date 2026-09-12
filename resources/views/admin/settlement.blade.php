@extends('layouts.admin')

@section('title', 'Settlement '.$settlement->provider_id.' — Agentpro admin')
@section('admin_title', $settlement->settlement_date?->format('j F Y') ?? 'Settlement')
@section('admin_lede', 'One payout, and everything this system can point at an order for.')

@section('admin_actions')
<a href="{{ route('admin.settlements') }}" class="btn btn-ghost btn-sm">All settlements</a>
@endsection

@section('admin_content')
@php use App\Support\Money; @endphp

{{--
    The provider's own arithmetic, written out as a sum rather than as four
    separate figures. Set out this way the reader can see whether it holds,
    which is the first thing to check and impossible to do from a grid of tiles.
--}}
<section class="balance">
    <div class="balanceline">
        <span><em>Gross taken</em><b>{{ Money::naira($settlement->total_amount) }}</b></span>
        <span class="op">&minus;</span>
        <span><em>Provider fees</em><b>{{ Money::naira($settlement->total_fees) }}</b></span>
        <span class="op">&minus;</span>
        <span><em>Refunds deducted</em><b>{{ Money::naira($settlement->deductions) }}</b></span>
        <span class="op">=</span>
        <span class="balancetotal"><em>Paid into the bank</em><b>{{ Money::naira($settlement->effective_amount) }}</b></span>
    </div>

    @unless ($settlement->arithmeticHolds())
        <p class="balancewarn">
            Those figures do not add up — the provider reports
            {{ Money::naira($settlement->effective_amount) }} paid out where the parts come to
            {{ Money::naira((float) $settlement->total_amount - (float) $settlement->total_fees - (float) $settlement->deductions) }}.
            Nothing else on this page should be trusted until that is explained.
        </p>
    @endunless

    <p class="balancemeta">
        {{ $settlement->statusLabel() }} ·
        <span class="mono">{{ $settlement->provider }} {{ $settlement->provider_id }}</span> ·
        {{ $settlement->transactions_count }} {{ Str::plural('transaction', $settlement->transactions_count) }},
        {{ $settlement->matched_count }} matched to orders
        @if ($settlement->unmatched_count > 0)
            , <b class="warnink">{{ $settlement->unmatched_count }} unmatched</b>
        @endif
        · {{ $settlement->reconciled_at ? 'checked '.$settlement->reconciled_at->diffForHumans() : 'never checked' }}
    </p>

    @if (abs((float) $settlement->variance) > 0.009)
        <p class="balancewarn">
            The transactions pulled for this settlement come to
            {{ Money::naira((float) $settlement->total_amount - (float) $settlement->variance) }},
            {{ Money::naira(abs($settlement->variance)) }} short of its stated total. That is a gap in
            what we read, not necessarily in what was paid — the unmatched count below is
            drawn from an incomplete list.
        </p>
    @endif
</section>

<div class="tablewrap">
<table class="admintable">
    <thead><tr><th>Reference</th><th>Order</th><th>Paid</th><th>Amount</th><th>Fee</th><th>Net</th><th></th></tr></thead>
    <tbody>
    @forelse ($transactions as $transaction)
        <tr @class(['overdue' => $transaction->needsAttention()])>
            <td class="mono">{{ Str::limit($transaction->reference ?? '—', 16, '') }}
                <span class="sub">{{ $transaction->channel ?? '' }}</span>
            </td>
            <td>
                @if ($transaction->isUnconfirmed())
                    {{-- Recoverable: we know the order, and the money is here. --}}
                    <b class="warnink">Never marked paid</b>
                    <span class="sub">
                        {{ $transaction->order->itemLabel() }} ·
                        {{ $transaction->order->user?->name }} ·
                        order reads &ldquo;{{ str_replace('_', ' ', $transaction->order->state) }}&rdquo;
                    </span>
                @elseif ($transaction->order)
                    {{ $transaction->order->itemLabel() }}
                    <span class="sub">{{ $transaction->order->user?->name }} · {{ str_replace('_', ' ', $transaction->order->state) }}</span>
                @else
                    <b class="warnink">No order</b>
                    <span class="sub">{{ $transaction->customer_email ?? 'no email on the transaction' }}</span>
                @endif
            </td>
            <td>{{ $transaction->paid_at?->format('j M H:i') ?? '—' }}</td>
            <td class="num">{{ Money::naira($transaction->amount) }}</td>
            <td class="num">{{ Money::naira($transaction->fees) }}</td>
            <td class="num">{{ Money::naira((float) $transaction->amount - (float) $transaction->fees) }}</td>
            <td>
                @if ($transaction->needsAttention() && $transaction->reference)
                    {{--
                        A button rather than something the nightly job does by
                        itself: the money is in the bank, which is strong
                        evidence, but confirming the order grants the entitlement
                        and messages the customer. That should be set off by a
                        person who looked at the row, with their name on it.
                    --}}
                    <form method="POST" action="{{ route('admin.settlements.confirm', $transaction) }}">
                        @csrf
                        <button class="btn btn-ghost btn-sm">Confirm this order</button>
                    </form>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="7" class="fhint">
            No transactions pulled for this settlement yet.
        </td></tr>
    @endforelse
    </tbody>
</table>
</div>

@if ($refunds->isNotEmpty())
    <section class="panel">
        <h2>Refunds around this payout</h2>
        {{-- Attributed by date, not by the provider telling us which payout a
             refund came out of — Paystack does not say. Read it as "processed
             around this settlement", not as a line in it. --}}
        <ul class="plainlist">
            @foreach ($refunds as $refund)
                <li>
                    <b>{{ Money::naira($refund->amount) }}</b>
                    on order <span class="mono">{{ Str::limit($refund->order?->uuid, 13, '') }}</span>
                    — {{ $refund->reason }}
                </li>
            @endforeach
        </ul>
    </section>
@endif
@endsection
