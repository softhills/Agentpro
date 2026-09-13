@extends('layouts.admin')

@section('title', 'Payouts — Agentpro admin')
@section('admin_title', 'Payouts')
@section('admin_lede', 'The only money that leaves to a destination somebody chose. Every payout needs a second admin, with no threshold that skips it.')

@section('admin_content')
@php
    use App\Support\Money;
    $me = auth()->id();
@endphp

<div class="metricgrid metricgrid-tight">
    <x-metric label="Owed to listers" :value="Money::naira($totals['owed'])"
              :note="Money::naira($totals['held']).' held for payouts in progress'"
              caption="the ledger, summed" />
    <x-metric label="Paid out" :value="Money::naira($totals['paid_30d'])"
              note="reached a bank account" caption="last 30 days" />
    <x-metric label="Provider balance"
              :value="$totals['balance'] === null ? '—' : Money::naira($totals['balance'])"
              :alert="$totals['balance'] !== null && $totals['balance'] < $totals['held']"
              :note="$totals['balance'] === null ? 'could not be read' : 'what can actually be sent today'"
              caption="live from the provider" />
    <x-metric label="Stuck with the bank" :value="$totals['stuck']"
              :alert="$totals['stuck'] > 0"
              :note="'sent over '.config('agentpro.payouts.stale_after_days').' days ago'"
              caption="should be zero" />
</div>

{{-- Waiting for approval ------------------------------------------------- --}}

<section class="panel {{ $pending->isNotEmpty() ? 'panel-warn' : '' }}">
    <h2>Waiting for approval</h2>
    <p class="panelhint">
        A payout cannot be approved by whoever asked for it — including a lister who
        requested their own. If the only button you can see is Cancel, that is why.
    </p>

    @forelse ($pending as $payout)
        <div class="refundrow refundrow-open">
            <span class="refundsum">
                {{ Money::naira($payout->amount) }}
                <em>{{ $payout->user?->name }}</em>
            </span>
            <span class="sub">
                {{ $payout->account?->account_name }} ·
                {{ $payout->account?->bank_name }} {{ $payout->account?->masked() }} ·
                asked by {{ $payout->requester?->name ?? 'unknown' }}, {{ $payout->created_at->diffForHumans() }}
                @unless ($payout->account?->isPayable())
                    · <b class="warnink">{{ $payout->account?->blocker() }}</b>
                @endunless
            </span>

            @if ($payout->requested_by === $me)
                <span class="sub">Waiting on another admin — you asked for this one.</span>
            @elseif ($payout->account?->isPayable())
                <form method="POST" action="{{ route('admin.payouts.approve', $payout) }}" class="inlineform">
                    @csrf
                    <button class="btn btn-blue btn-sm">Approve &amp; send</button>
                </form>
            @endif

            <form method="POST" action="{{ route('admin.payouts.cancel', $payout) }}" class="inlineform">
                @csrf
                <input name="why" class="finput finput-sm" placeholder="Why cancel" required>
                <button class="linkbtn">Cancel</button>
            </form>
        </div>
    @empty
        <p class="allclear"><x-icon name="check" stroke-width="2.5" /> Nothing waiting.</p>
    @endforelse
</section>

{{-- Bank accounts needing a look ----------------------------------------- --}}

@if ($unmatched->isNotEmpty())
    <section class="panel panel-warn">
        <h2>Bank names that do not match</h2>
        <p class="panelhint">
            The bank's name for these accounts is not the identity we verified. That is
            often legitimate — an agent receiving into a registered business account — so
            it is a check, not a refusal. Nothing can be sent until one of these is agreed.
        </p>
        <ul class="plainlist">
            @foreach ($unmatched as $account)
                <li>
                    <span>
                        <b>{{ $account->account_name }}</b> at {{ $account->bank_name }} {{ $account->masked() }}
                        <span class="sub">verified identity: {{ $account->user?->name }}</span>
                    </span>
                    <form method="POST" action="{{ route('admin.payout-accounts.approve', $account) }}">
                        @csrf
                        <button class="btn btn-ghost btn-sm">This is them</button>
                    </form>
                </li>
            @endforeach
        </ul>
    </section>
@endif

{{-- Credit a lister ------------------------------------------------------ --}}

<section class="taxblock">
    <h2>Credit a lister</h2>
    <p class="panelhint">
        The only thing that puts money on a ledger by hand. The memo is what the lister
        sees on their own statement, so write it for them.
    </p>

    <details class="addbox">
        <summary class="btn btn-blue btn-sm">Add a credit</summary>
        <form method="POST" action="{{ route('admin.payouts.credit') }}" class="taxform">
            @csrf
            <div class="fieldset">
                <label class="flabel" for="credit_user">Lister</label>
                <select id="credit_user" name="user_id" class="finput" required>
                    <option value="">Choose a lister</option>
                    @foreach ($owed as $row)
                        <option value="{{ $row->user_id }}">{{ $row->user?->name }} — {{ Money::naira($row->balance) }} owed</option>
                    @endforeach
                </select>
                <span class="fhint">Only listers with a balance are listed; use the People screen to find anyone else.</span>
            </div>
            <div class="fieldset">
                <label class="flabel" for="credit_kind">What for</label>
                <select id="credit_kind" name="kind" class="finput" required>
                    <option value="listing_incentive">Listing incentive</option>
                    <option value="referral">Referral</option>
                    <option value="service_credit">Service credit</option>
                    <option value="correction">Correction</option>
                </select>
            </div>
            <div class="fieldset">
                <label class="flabel" for="credit_amount">Amount</label>
                <input id="credit_amount" name="amount" type="number" step="0.01" min="1" class="finput" required>
            </div>
            <div class="fieldset">
                <label class="flabel" for="credit_memo">Memo</label>
                <input id="credit_memo" name="memo" class="finput" maxlength="200" required
                       placeholder="Launch incentive — 5 verified listings in September">
            </div>
            <button class="btn btn-blue btn-sm">Credit</button>
        </form>
    </details>
</section>

{{-- Balances ------------------------------------------------------------- --}}

<section class="taxblock">
    <h2>Owed</h2>
    <div class="tablewrap">
    <table class="admintable">
        <thead><tr><th>Lister</th><th>Identity</th><th>Balance</th></tr></thead>
        <tbody>
        @forelse ($owed as $row)
            <tr>
                <td><strong>{{ $row->user?->name ?? 'deleted' }}</strong><span class="sub">{{ $row->user?->email }}</span></td>
                <td>
                    <span class="vstate vstate-{{ $row->user?->verification_state }}">{{ $row->user?->verification_state }}</span>
                </td>
                <td class="num">{{ Money::naira($row->balance) }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="fhint">Nothing is owed to anyone.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</section>

{{-- History -------------------------------------------------------------- --}}

<section class="taxblock">
    <h2>Recent payouts</h2>
    <div class="tablewrap">
    <table class="admintable">
        <thead><tr><th>Lister</th><th>Destination</th><th>Amount</th><th>State</th><th></th></tr></thead>
        <tbody>
        @forelse ($recent as $payout)
            <tr @class(['overdue' => $payout->isStuck() || in_array($payout->state, ['failed', 'reversed'], true)])>
                <td>{{ $payout->user?->name }}<span class="sub">{{ $payout->created_at->format('j M H:i') }}</span></td>
                <td>{{ $payout->account?->bank_name }}<span class="sub">{{ $payout->account?->masked() }}</span></td>
                <td class="num">{{ Money::naira($payout->amount) }}</td>
                <td>
                    <span class="ostate ostate-{{ $payout->state === 'paid' ? 'paid' : ($payout->state === 'submitted' ? 'pending' : 'failed') }}">
                        {{ $payout->stateLabel() }}
                    </span>
                    @if ($payout->failure_reason)
                        <span class="sub">{{ $payout->failure_reason }}</span>
                    @elseif ($payout->isStuck())
                        <span class="sub warnink">sent {{ $payout->submitted_at->diffForHumans() }}</span>
                    @endif
                </td>
                <td>
                    {{--
                        A failed submission does not put the money back on its
                        own: the call may have reached the provider before it
                        failed, and crediting automatically could pay the same
                        money twice. Somebody checks, then presses this.
                    --}}
                    @if ($payout->state === 'failed' && $payout->ledgerEntries()->where('kind', 'payout_returned')->doesntExist())
                        <details class="refundbox">
                            <summary class="linkbtn">Return to balance</summary>
                            <form method="POST" action="{{ route('admin.payouts.return', $payout) }}" class="taxform taxform-tight">
                                @csrf
                                <input name="why" class="finput finput-sm" required
                                       placeholder="How you know it did not go out">
                                <button class="btn btn-ghost btn-sm">Return</button>
                            </form>
                        </details>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="fhint">No payouts yet.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</section>
@endsection
