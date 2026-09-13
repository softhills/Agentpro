@extends('layouts.app')

@section('title', 'Getting paid — Agentpro')

@section('content')
@php use App\Support\Money; @endphp

<div class="container formwrap" style="max-width:760px">
    <h1>Getting paid</h1>
    <x-flash />
    <x-form-errors />

    <section class="balancecard">
        <span class="balancelabel">Available to withdraw</span>
        <strong>{{ Money::naira($balance) }}</strong>
        @if ($balance > 0 && $balance < $minimum)
            <p class="fhint">The smallest payout is {{ Money::naira($minimum) }} — bank transfers carry a flat
               fee, so anything less costs more in charges than it moves.</p>
        @endif
    </section>

    {{-- Bank details ------------------------------------------------------ --}}

    <section class="formsec">
        <h2>Where your money goes</h2>

        @if ($account)
            <div class="bankcard">
                <div>
                    {{-- The bank's name for the account, never the one typed. --}}
                    <strong>{{ $account->account_name }}</strong>
                    <span class="sub">{{ $account->bank_name }} · {{ $account->masked() }}</span>
                </div>
                @if ($account->isPayable())
                    <span class="ostate ostate-paid">ready</span>
                @else
                    <span class="ostate ostate-pending">on hold</span>
                @endif
            </div>

            @if ($blocker = $account->blocker())
                <p class="prefnote prefnote-warn">{{ $blocker }}</p>
            @endif
        @else
            <p class="secblurb">No bank details yet. Add them to be paid.</p>
        @endif

        <details class="addbox" @if (! $account) open @endif>
            <summary class="btn btn-ghost btn-sm">{{ $account ? 'Change bank details' : 'Add bank details' }}</summary>

            <p class="secblurb" style="margin-top:12px">
                We check the account number with the bank and show you the name it comes back
                with — we never take the name from this form. New details are held for
                {{ config('agentpro.payouts.account_hold_hours') }} hours before anything can be
                sent to them, and we message you when they change. That is so a stolen login
                cannot quietly redirect your money.
            </p>

            <form method="POST" action="{{ route('lister.payouts.account') }}" class="stack">
                @csrf
                <div class="fieldset">
                    <label class="flabel" for="bank_code">Bank</label>
                    <select id="bank_code" name="bank_code" class="finput" required>
                        <option value="">Choose your bank</option>
                        @foreach ($banks as $code => $name)
                            <option value="{{ $code }}" @selected(old('bank_code') === (string) $code)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fieldset">
                    <label class="flabel" for="account_number">Account number</label>
                    <input id="account_number" name="account_number" class="finput" inputmode="numeric"
                           maxlength="10" required value="{{ old('account_number') }}" placeholder="0123456789">
                </div>
                <button class="btn btn-blue">Check and save</button>
            </form>
        </details>
    </section>

    {{-- Request ----------------------------------------------------------- --}}

    @if ($balance >= $minimum && $account?->isPayable())
        <section class="formsec">
            <h2>Withdraw</h2>
            <p class="secblurb">
                Payouts are checked by our team before they go out, so this is not instant.
                You will get a message when the money is sent.
            </p>
            <form method="POST" action="{{ route('lister.payouts.request') }}" class="stack">
                @csrf
                <div class="fieldset">
                    <label class="flabel" for="amount">How much</label>
                    <input id="amount" name="amount" type="number" step="0.01" class="finput"
                           min="{{ $minimum }}" max="{{ $balance }}" value="{{ $balance }}" required>
                    <span class="fhint">Up to {{ Money::naira($balance) }}.</span>
                </div>
                <button class="btn btn-blue">Request payout</button>
            </form>
        </section>
    @endif

    {{-- Payouts ----------------------------------------------------------- --}}

    @if ($payouts->isNotEmpty())
        <section class="formsec">
            <h2>Payouts</h2>
            <ul class="plainlist">
                @foreach ($payouts as $payout)
                    <li>
                        <b>{{ Money::naira($payout->amount) }}</b> —
                        {{ $payout->stateLabel() }},
                        {{ $payout->created_at->diffForHumans() }}
                        <span class="sub">
                            {{ $payout->account?->bank_name }} {{ $payout->account?->masked() }}
                            @if ($payout->failure_reason) · {{ $payout->failure_reason }} @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Statement --------------------------------------------------------- --}}

    <section class="formsec">
        <h2>Statement</h2>
        <p class="secblurb">
            Every entry that makes up your balance. A figure on its own is not something
            either of us can check.
        </p>

        <div class="tablewrap">
        <table class="admintable">
            <thead><tr><th>Date</th><th>What for</th><th>In</th><th>Out</th></tr></thead>
            <tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td>{{ $entry->created_at->format('j M Y') }}</td>
                    <td>{{ $entry->kindLabel() }}<span class="sub">{{ $entry->memo }}</span></td>
                    <td class="num">{{ $entry->direction === 'credit' ? Money::naira($entry->amount) : '' }}</td>
                    <td class="num">{{ $entry->direction === 'debit' ? Money::naira($entry->amount) : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="fhint">Nothing here yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </section>
</div>
@endsection
