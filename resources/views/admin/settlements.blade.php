@extends('layouts.admin')

@section('title', 'Settlements — Agentpro admin')
@section('admin_title', 'Settlements')
@section('admin_lede', 'What the bank actually received, matched against what this system says it charged. The matches are routine; the two lists further down are the point.')

@section('admin_actions')
<form method="POST" action="{{ route('admin.settlements.reconcile') }}">
    @csrf
    <button class="btn btn-blue btn-sm">Reconcile now</button>
</form>
@endsection

@section('admin_content')
@php use App\Support\Money; @endphp

<div class="metricgrid metricgrid-tight">
    <x-metric label="Settled to bank" :value="Money::naira($totals['settled_30d'])"
              :note="Money::naira($totals['fees_30d']).' taken in provider fees'"
              caption="last 30 days, net" />
    <x-metric label="Unaccounted payments" :value="$totals['orphans']"
              :alert="$totals['orphans'] > 0"
              note="settled with no order behind them" caption="must be zero" />
    <x-metric label="Never settled" :value="$unsettledCount"
              :alert="$unsettledCount > 0"
              note="paid here, absent from every payout" caption="must be zero" />
    <x-metric label="Do not reconcile" :value="$totals['discrepancies']"
              :alert="$totals['discrepancies'] > 0"
              :note="$totals['unreconciled'].' not yet checked'" caption="needs a person" />
</div>

@if ($unsettled->isNotEmpty())
    {{--
        Orders the customer was told succeeded, that no payout ever contained.
        Listed before the settlements themselves because a settlement that
        balances needs nobody, and one of these always does.
    --}}
    <section class="panel panel-bad">
        <h2>Paid here, never settled</h2>
        <p class="panelhint">
            Each of these was marked paid and the money has not arrived. Usually a reversal
            we did not see, or a reference that stopped matching. Showing
            {{ $unsettled->count() }} of {{ $unsettledCount }}.
        </p>
        <div class="tablewrap">
            <table class="admintable">
                <thead><tr><th>Order</th><th>Customer</th><th>Paid</th><th>Amount</th><th>Reference</th></tr></thead>
                <tbody>
                @foreach ($unsettled as $order)
                    <tr>
                        <td class="mono">{{ Str::limit($order->uuid, 13, '') }}</td>
                        <td>{{ $order->user?->name ?? 'deleted' }}<span class="sub">{{ $order->itemLabel() }}</span></td>
                        <td>{{ $order->paid_at->format('j M Y') }}<span class="sub">{{ $order->paid_at->diffForHumans() }}</span></td>
                        <td class="num">{{ Money::naira($order->amount) }}</td>
                        <td class="mono sub">{{ $order->paystack_reference ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

@if ($stuckRefunds->isNotEmpty())
    <section class="panel panel-warn">
        <h2>Refunds still with the provider</h2>
        <p class="panelhint">
            Sent more than {{ config('agentpro.refunds.stale_after_days') }} days ago and not confirmed.
            The customer is waiting and does not know why.
        </p>
        <ul class="plainlist">
            @foreach ($stuckRefunds as $refund)
                <li>
                    <b>{{ Money::naira($refund->amount) }}</b>
                    on order <span class="mono">{{ Str::limit($refund->order?->uuid, 13, '') }}</span>
                    — sent {{ $refund->submitted_at->diffForHumans() }},
                    provider says &ldquo;{{ $refund->provider_status ?? 'nothing' }}&rdquo;
                </li>
            @endforeach
        </ul>
    </section>
@endif

<div class="tablewrap">
<table class="admintable">
    <thead>
        <tr>
            <th>Settlement</th><th>Status</th><th>Gross</th><th>Fees</th>
            <th>Deductions</th><th>To bank</th><th>Accounted for</th>
        </tr>
    </thead>
    <tbody>
    @forelse ($settlements as $settlement)
        <tr @class(['overdue' => $settlement->reconciliation_state === 'discrepancy'])>
            <td>
                <a href="{{ route('admin.settlements.show', $settlement) }}" class="linkbtn">
                    {{ $settlement->settlement_date?->format('j M Y') ?? 'undated' }}
                </a>
                <span class="sub mono">{{ $settlement->provider }} · {{ $settlement->provider_id }}</span>
            </td>
            <td><span class="ostate ostate-{{ $settlement->status === 'success' ? 'paid' : 'pending' }}">{{ $settlement->statusLabel() }}</span></td>
            <td class="num">{{ Money::naira($settlement->total_amount) }}</td>
            <td class="num">{{ Money::naira($settlement->total_fees) }}</td>
            <td class="num">{{ (float) $settlement->deductions > 0 ? Money::naira($settlement->deductions) : '—' }}</td>
            <td class="num"><b>{{ Money::naira($settlement->effective_amount) }}</b></td>
            <td>
                @if ($settlement->reconciliation_state === 'unreconciled')
                    <span class="tag tag-drop">not checked</span>
                @elseif ($settlement->isBalanced())
                    <span class="ostate ostate-paid">balanced</span>
                    <span class="sub">{{ $settlement->matched_count }} {{ Str::plural('transaction', $settlement->matched_count) }}</span>
                @else
                    <span class="ostate ostate-failed">{{ $settlement->explainedShare() }}% explained</span>
                    <span class="sub">
                        @if ($settlement->unmatched_count > 0)
                            {{ $settlement->unmatched_count }} unmatched ({{ Money::naira($settlement->unmatched_amount) }})
                        @endif
                        @if (abs((float) $settlement->variance) > 0.009)
                            · {{ Money::naira(abs($settlement->variance)) }} of transactions not seen
                        @endif
                        @unless ($settlement->arithmeticHolds())
                            · provider totals do not add up
                        @endunless
                    </span>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="7" class="fhint">
            No settlements pulled yet. Reconciliation runs at 06:30 daily, or press Reconcile now.
        </td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div style="margin-top:16px">{{ $settlements->links() }}</div>
@endsection
