@extends('layouts.admin')

@section('title', 'Admin dashboard — Agentpro')
@section('admin_title', 'Dashboard')
@section('admin_lede', 'Measured against the objectives the release is judged on, not whatever was easy to count.')

@section('admin_content')
@php use App\Support\Money; @endphp

{{-- Things that need a person, first. Everything below this is context. --}}
@php
    /*
     * Configuration rather than work, and it still belongs on this rail. A tile
     * licence breach, or a key left as a placeholder, shows up as grey squares
     * on a visitor's screen at a moment the provider chooses — and there is
     * nowhere else in this console anybody would look for it.
     */
    $tileProblems = \App\Support\MapTiles::problems();

    $alerts = collect([
        $moderation['breaching'] > 0
            ? ['bad', $moderation['breaching'].' '.Str::plural('listing', $moderation['breaching']).' past the '.$moderation['sla_hours'].'-hour review SLA', route('admin.queue')]
            : null,
        $revenue['unredeemed'] > 0
            ? ['bad', $revenue['unredeemed'].' paid '.Str::plural('capture', $revenue['unredeemed']).' with no date booked — money taken, nothing delivered', route('admin.orders')]
            : null,
        $fees['missing'] > 0
            ? ['bad', $fees['missing'].' live '.Str::plural('listing', $fees['missing']).' with no cost breakdown — publishing should have blocked this', route('admin.listings', ['state' => 'published'])]
            : null,
        // FR-M11-06. Both directions of the same failure: money we cannot
        // explain, and money we claimed and never received. Shown only to
        // admins, since only they can open the screen that resolves them.
        $money && $money['orphans'] > 0
            ? ['bad', $money['orphans'].' settled '.Str::plural('payment', $money['orphans']).' worth '.Money::naira($money['orphan_amount']).' with no order behind '.($money['orphans'] === 1 ? 'it' : 'them').' — somebody paid and this system does not know', route('admin.settlements')]
            : null,
        $money && $money['unsettled'] > 0
            ? ['bad', $money['unsettled'].' paid '.Str::plural('order', $money['unsettled']).' worth '.Money::naira($money['unsettled_amount']).' that never settled', route('admin.orders', ['view' => 'unsettled'])]
            : null,
        $money && $money['refunds_awaiting'] > 0
            ? ['warn', $money['refunds_awaiting'].' '.Str::plural('refund', $money['refunds_awaiting']).' waiting on a second approver', route('admin.orders')]
            : null,
        $money && $money['refunds_stuck'] > 0
            ? ['warn', $money['refunds_stuck'].' '.Str::plural('refund', $money['refunds_stuck']).' still with the provider after '.config('agentpro.refunds.stale_after_days').' days', route('admin.settlements')]
            : null,
        $money && $money['discrepancies'] > 0
            ? ['warn', $money['discrepancies'].' '.Str::plural('settlement', $money['discrepancies']).' that do not reconcile', route('admin.settlements')]
            : null,
        // The quiet failure: every figure above stops changing when the job
        // stops running, and a stalled reconciliation looks exactly like a
        // clean one.
        $money && ($money['last_reconciled'] === null || $money['last_reconciled']->diffInHours(now()) > 48)
            ? ['warn', 'Settlement reconciliation last ran '.($money['last_reconciled']?->diffForHumans() ?? 'never'), route('admin.settlements')]
            : null,
        $operations['awaiting_capture'] > 0
            ? ['warn', $operations['awaiting_capture'].' capture '.Str::plural('visit', $operations['awaiting_capture']).' past their slot and not marked captured', route('admin.operations')]
            : null,
        $supply['pending_listers'] > 0
            ? ['warn', $supply['pending_listers'].' '.Str::plural('lister', $supply['pending_listers']).' waiting on identity verification', route('admin.users', ['state' => 'pending'])]
            : null,
        $operations['slots_open'] === 0
            ? ['warn', 'No capture slots open — the only paid feature cannot be booked', route('admin.operations')]
            : null,

        // No link on these: the fix is in .env rather than on a screen, and a
        // link that goes nowhere teaches people to ignore the whole rail.
        ...array_map(fn (string $problem) => ['warn', $problem, null], $tileProblems),
    ])->filter();
@endphp

@if ($alerts->isNotEmpty())
    <section class="attention">
        <h2>Needs attention</h2>
        @foreach ($alerts as [$level, $text, $link])
            @if ($link)
                <a href="{{ $link }}" @class(['attn', 'attn-bad' => $level === 'bad', 'attn-warn' => $level === 'warn'])>
                    <span>{{ $text }}</span>
                    <x-icon name="chevron" style="transform:rotate(-90deg);width:14px;height:14px" />
                </a>
            @else
                <div @class(['attn', 'attn-flat', 'attn-bad' => $level === 'bad', 'attn-warn' => $level === 'warn'])>
                    <span>{{ $text }}</span>
                </div>
            @endif
        @endforeach
    </section>
@else
    <section class="attention">
        <h2>Needs attention</h2>
        <p class="allclear"><x-icon name="check" stroke-width="2.5" /> Nothing is waiting. The queue is clear and every paid capture has a date.</p>
    </section>
@endif

<div class="metricgrid">
    <x-metric label="Live listings" :value="number_format($trust['live'])"
              :note="$trust['realsure'].' RealSure verified ('.$trust['realsure_share'].'%)'"
              :progress="$trust['realsure_share']" :target="$trust['target_share']"
              caption="O1 · target 25% verified" />

    <x-metric label="Verified listers" :value="number_format($supply['verified_listers'])"
              :note="$supply['pending_listers'].' awaiting checks'"
              caption="O4 · target 1,200" />

    <x-metric label="Review queue" :value="$moderation['queue_depth']"
              :note="$moderation['median_hours'] !== null ? 'median decision '.$moderation['median_hours'].'h' : 'no decisions in 30 days'"
              :alert="$moderation['breaching'] > 0"
              :caption="'O7 · SLA '.$moderation['sla_hours'].'h'" />

    <x-metric label="Cost breakdowns" :value="$fees['compliant'].'%'"
              :note="$fees['missing'] > 0 ? $fees['missing'].' live listings non-compliant' : 'every live listing complete'"
              :alert="$fees['missing'] > 0"
              caption="O3 · must be 100%" />

    <x-metric label="3D tours live" :value="number_format($immersive['tours'])"
              :note="$immersive['videos'].' listings with video'"
              caption="O2 · target 400" />

    <x-metric label="Paid orders" :value="number_format($revenue['orders_paid'])"
              :note="Money::naira($revenue['gross']).' gross · '.Money::naira($revenue['gross_30d']).' last 30d'"
              caption="O6 · scan upgrade revenue" />

    @if ($money)
        {{-- Gross is what we charged; this is what the bank actually received. --}}
        <x-metric label="Settled to bank" :value="Money::naira($money['settled_30d'], true)"
                  :note="Money::naira($money['fees_30d']).' in provider fees · '.Money::naira($revenue['refunded']).' refunded'"
                  :alert="$money['orphans'] > 0 || $money['unsettled'] > 0"
                  caption="FR-M11-06 · last 30 days, net" />
    @endif

    <x-metric label="Reports" :value="$integrity['per_thousand']"
              :note="$integrity['reports'].' total · '.$integrity['reports_30d'].' in 30 days'"
              :alert="$integrity['per_thousand'] > $integrity['target']"
              caption="O8 · per 1,000 live, target under 5" />

    <x-metric label="Saved searches" :value="number_format($engagement['saved_searches'])"
              :note="number_format($engagement['saves']).' saved listings · '.number_format($engagement['contacts']).' enquiries'"
              caption="the loop that brings seekers back" />
</div>

<div class="admincols">
    <section class="panel">
        <h2><x-icon name="pin" />Capture operations</h2>
        <dl class="kvlist">
            <div><dt>Scheduled visits</dt><dd>{{ $operations['scheduled'] }}</dd></div>
            <div><dt>Past their slot</dt><dd>{{ $operations['awaiting_capture'] }}</dd></div>
            <div><dt>Tours delivered</dt><dd>{{ $operations['live'] }}</dd></div>
            <div><dt>Open slots ahead</dt><dd>{{ $operations['slots_open'] }}</dd></div>
        </dl>
        <p class="fhint">
            Capacity, not demand, is the constraint on the only paid feature in R1.
            <a href="{{ route('admin.operations') }}">Open coverage &amp; capacity</a>
        </p>
    </section>

    <section class="panel">
        <h2><x-icon name="plan" />Recent activity</h2>
        <ul class="activity">
            @forelse ($activity as $event)
                <li>
                    <code>{{ $event->action }}</code>
                    <span>{{ $event->actor ?? 'system' }}</span>
                    <time>{{ \Illuminate\Support\Carbon::parse($event->created_at)->diffForHumans(short: true) }}</time>
                </li>
            @empty
                <li class="fhint">Nothing recorded yet.</li>
            @endforelse
        </ul>
        <p class="fhint"><a href="{{ route('admin.audit') }}">Full audit log</a></p>
    </section>
</div>
@endsection
