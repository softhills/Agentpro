@extends('layouts.admin')

@section('title', 'Orders — Agentpro admin')
@section('admin_title', 'Orders')
@section('admin_lede', 'Recording a refund here does not move money — issue it in Paystack and reconcile against this record.')

@section('admin_content')
@php use App\Support\Money; @endphp

<div class="metricgrid metricgrid-tight">
    <x-metric label="Gross paid" :value="Money::naira($totals['paid'])" caption="all time" />
    <x-metric label="Refunded" :value="Money::naira($totals['refunded'])" caption="recorded against orders" />
    <x-metric label="Paid, nothing booked" :value="$totals['unredeemed']"
              :alert="$totals['unredeemed'] > 0"
              note="money taken, capture not scheduled" caption="FR-M4-07 · should be zero" />
</div>

<form method="GET" class="adminfilters">
    <select name="state" class="finput">
        <option value="">Any state</option>
        @foreach (['pending','paid','failed','refunded','partially_refunded','cancelled'] as $s)
            <option value="{{ $s }}" @selected(request('state') === $s)>{{ str_replace('_',' ',$s) }} ({{ $counts[$s] ?? 0 }})</option>
        @endforeach
    </select>
    <button type="submit" class="btn btn-blue btn-sm">Filter</button>
</form>

<div class="tablewrap">
<table class="admintable">
    <thead><tr><th>Order</th><th>Customer</th><th>Item</th><th>Amount</th><th>State</th><th>Capture</th><th>Refund</th></tr></thead>
    <tbody>
    @forelse ($orders as $order)
        <tr>
            <td class="mono sub">{{ Str::limit($order->uuid, 13, '') }}<br>{{ $order->paid_at?->format('j M H:i') ?? '—' }}</td>
            <td>
                {{ $order->user?->name ?? 'deleted' }}
                <span class="sub">{{ $order->property?->title }}</span>
            </td>
            <td>{{ $order->itemLabel() }}<span class="sub">{{ $order->paystack_channel ?? '—' }}</span></td>
            <td class="num">
                {{ Money::naira($order->amount) }}
                @if ($order->refunded_amount > 0)
                    <span class="sub">less {{ Money::naira($order->refunded_amount) }}</span>
                @endif
            </td>
            <td><span class="ostate ostate-{{ $order->state }}">{{ str_replace('_',' ',$order->state) }}</span></td>
            <td>
                @if ($order->scanJob)
                    {{ $order->scanJob->stateLabel() }}
                    <span class="sub">{{ $order->scanJob->scheduled_for?->format('j M, g:ia') }}</span>
                @elseif ($order->isUnredeemed())
                    <span class="tag tag-drop">not booked</span>
                @else
                    —
                @endif
            </td>
            <td>
                @if ($order->isPaid() || $order->state === 'partially_refunded')
                    <details class="refundbox">
                        <summary class="linkbtn">Refund</summary>
                        <form method="POST" action="{{ route('admin.orders.refund', $order) }}" class="stack" style="margin-top:8px">
                            @csrf
                            <input name="amount" type="number" step="0.01" class="finput finput-sm"
                                   max="{{ $order->amount - $order->refunded_amount }}"
                                   value="{{ $order->amount - $order->refunded_amount }}" required>
                            <input name="reason" class="finput finput-sm" placeholder="Why" required>
                            <button type="submit" class="btn btn-ghost btn-sm">Record refund</button>
                        </form>
                    </details>
                @else
                    —
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="7" class="fhint">No orders match that filter.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div style="margin-top:16px">{{ $orders->links() }}</div>
@endsection
