@extends('layouts.admin')

@section('title', 'Orders — Agentpro admin')
@section('admin_title', 'Orders')
@section('admin_lede', 'A refund goes back along the transaction that paid it, to the payer and nowhere else. Nothing is marked refunded until the provider confirms the money moved.')

@section('admin_content')
@php
    use App\Support\Money;
    $me = auth()->id();
    // Moderators can read the ledger; only admins can move money on it.
    $canRefund = auth()->user()->isStaff('admin');
@endphp

<div class="metricgrid metricgrid-tight">
    <x-metric label="Gross paid" :value="Money::naira($totals['paid'])" caption="all time" />
    <x-metric label="Refunded" :value="Money::naira($totals['refunded'])"
              :note="$totals['inFlight'] > 0 ? Money::naira($totals['inFlight']).' still in flight' : 'nothing in flight'"
              caption="confirmed by the provider" />
    <x-metric label="Awaiting approval" :value="$totals['awaiting']"
              :alert="$totals['awaiting'] > 0"
              note="needs a second admin" caption="FR-M11-05" />
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
    <label class="fcheck">
        <input type="checkbox" name="view" value="unsettled" @checked(request('view') === 'unsettled')>
        <span>Paid but never settled</span>
    </label>
    <button type="submit" class="btn btn-blue btn-sm">Filter</button>
</form>

<div class="tablewrap">
<table class="admintable">
    <thead><tr><th>Order</th><th>Customer</th><th>Item</th><th>Amount</th><th>State</th><th>Capture</th><th>Refunds</th></tr></thead>
    <tbody>
    @forelse ($orders as $order)
        @php $refundable = $order->refundableAmount(); @endphp
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
                {{-- What the bank received, which is the figure Finance is
                     asked about — not the figure the customer was charged. --}}
                @if ($order->isSettled())
                    <span class="sub" title="Settled {{ $order->settled_at?->format('j M Y') }}">
                        net {{ Money::naira($order->net_amount) }}
                    </span>
                @elseif ($order->isPaid() && $order->paid_at?->addDays((int) config('agentpro.settlement.grace_days'))->isPast())
                    <span class="tag tag-drop" title="Paid here, but no settlement from the provider contains it">unsettled</span>
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
            <td class="refundcell">
                @foreach ($order->refunds as $refund)
                    <div @class(['refundrow', 'refundrow-open' => $refund->needsApproval(), 'refundrow-bad' => $refund->state === 'failed'])>
                        <span class="refundsum">
                            {{ Money::naira($refund->amount) }}
                            <em>{{ $refund->stateLabel() }}</em>
                        </span>
                        <span class="sub">
                            {{ $refund->reason }} ·
                            {{ $refund->requester?->name ?? 'unknown' }},
                            {{ $refund->created_at->diffForHumans() }}
                            @if ($refund->isStuck()) · <b class="warnink">chase this</b> @endif
                            @if ($refund->failure_reason) · {{ $refund->failure_reason }} @endif
                        </span>

                        @if ($refund->needsApproval() && $canRefund)
                            {{-- The requester cannot approve their own, so they
                                 are shown why the button is not there rather
                                 than being shown a button that refuses. --}}
                            @if ($refund->requested_by === $me)
                                <span class="sub">Waiting on another admin — you asked for this one.</span>
                                <form method="POST" action="{{ route('admin.refunds.cancel', $refund) }}" class="inlineform">
                                    @csrf
                                    <input name="why" class="finput finput-sm" placeholder="Why cancel" required>
                                    <button class="linkbtn">Cancel</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.refunds.approve', $refund) }}" class="inlineform">
                                    @csrf
                                    <button class="btn btn-blue btn-sm">Approve &amp; send</button>
                                </form>
                            @endif
                        @endif
                    </div>
                @endforeach

                @if ($canRefund && $refundable > 0.009 && in_array($order->state, ['paid', 'partially_refunded'], true))
                    <details class="refundbox">
                        <summary class="linkbtn">Refund</summary>
                        <form method="POST" action="{{ route('admin.orders.refund', $order) }}" class="stack" style="margin-top:8px">
                            @csrf
                            <input name="amount" type="number" step="0.01" class="finput finput-sm"
                                   max="{{ $refundable }}" value="{{ $refundable }}" required>
                            <input name="reason" class="finput finput-sm" placeholder="Why" required>
                            <button type="submit" class="btn btn-ghost btn-sm">
                                {{ $refundable > $threshold ? 'Request refund' : 'Refund' }}
                            </button>
                            @if ($refundable > $threshold)
                                <span class="fhint">Over {{ Money::naira($threshold) }} — a second admin has to approve it.</span>
                            @endif
                        </form>
                    </details>
                @elseif ($order->refunds->isEmpty())
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
