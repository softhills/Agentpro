@extends('layouts.app')

@section('title', 'How '.$property->title.' is doing — Agentpro')

@section('content')
@php
    $peak = max(1, collect($stats['daily'])->max('views'));
@endphp

<div class="container formwrap" style="max-width:820px">
    <p class="crumb"><a href="{{ route('lister.dashboard') }}">← Your listings</a></p>

    <h1>How this listing is doing</h1>
    <p class="secblurb" style="max-width:62ch">
        <strong>{{ $property->title }}</strong> · {{ $property->area?->name ?? $property->city }}.
        Everything below covers the last {{ $stats['days'] }} days unless it says otherwise.
    </p>

    {{-- The four numbers FR-M13-01 asks for -------------------------------- --}}

    <div class="metricgrid metricgrid-tight">
        <x-metric label="Views" :value="number_format($stats['views'])"
                  :note="number_format($stats['views_all']).' since it went up'"
                  caption="people who opened it" />
        <x-metric label="Saves" :value="number_format($stats['saves']['recent'])"
                  :note="number_format($stats['saves']['total']).' saved in total'"
                  caption="kept for later" />
        <x-metric label="Enquiries" :value="number_format($stats['contacts']['total'])"
                  note="times someone started a contact"
                  caption="phone, WhatsApp or email" />
        <x-metric label="3D tour opened" :value="number_format($stats['tours'])"
                  :note="$stats['dwell']['median'] === null
                      ? 'no timings yet'
                      : 'typically '.$stats['dwell']['median'].'s spent in it'"
                  caption="the tour, not the photos" />
    </div>

    {{-- Views over time ---------------------------------------------------- --}}

    <section class="formsec">
        <h2>Views, day by day</h2>

        @if ($stats['views'] === 0)
            <p class="secblurb">
                Nobody has opened this listing yet.
                @if ($property->lifecycle_state->value !== 'published')
                    It is <b>{{ str_replace('_', ' ', $property->lifecycle_state->value) }}</b>, so it is not
                    on the market — that is the reason, rather than anything about the listing itself.
                @endif
            </p>
        @else
            {{--
                Bars rather than a line. A line drawn through daily counts
                invites reading a trend into what is mostly noise at this
                volume; a bar chart shows the days as the separate things they
                are, and a day with nothing in it looks empty rather than
                looking like part of a slope.
            --}}
            <div class="spark" role="img"
                 aria-label="Daily views over the last {{ $stats['days'] }} days, peaking at {{ $peak }}">
                @foreach ($stats['daily'] as $point)
                    <span class="sparkbar" style="--h:{{ round($point['views'] / $peak * 100) }}%"
                          title="{{ \Illuminate\Support\Carbon::parse($point['day'])->format('j M') }}: {{ $point['views'] }}"></span>
                @endforeach
            </div>
            <p class="sparkaxis">
                <span>{{ \Illuminate\Support\Carbon::parse($stats['daily'][0]['day'])->format('j M') }}</span>
                <span>peak {{ $peak }} a day</span>
                <span>today</span>
            </p>
        @endif
    </section>

    {{-- Enquiries by route -------------------------------------------------- --}}

    <section class="formsec">
        <h2>How people got in touch</h2>
        <p class="secblurb">
            Worth more than the total: enquiries that all arrive on WhatsApp need a different
            day from a phone that rings, and the total alone cannot tell you which you have.
        </p>

        @if ($stats['contacts']['total'] === 0)
            <p class="prefnote">No enquiries yet in this window.</p>
        @else
            <ul class="plainlist">
                @foreach ($stats['contacts']['by_mode'] as $mode => $count)
                    <li>
                        <span>
                            <b>{{ ucfirst($mode) }}</b>
                            <span class="sub">
                                {{ $count }} {{ \Illuminate\Support\Str::plural('enquiry', $count) }}
                                · {{ round($count / $stats['contacts']['total'] * 100) }}%
                            </span>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Honesty about the numbers ------------------------------------------- --}}

    <section class="formsec">
        <h2>What these numbers are</h2>
        <p class="secblurb">
            A <b>view</b> is one person opening the listing, counted once per visit however many
            times they reload it — so this is smaller than a hit count and closer to the truth.
            Known crawlers and bots are excluded. Timings on the 3D tour come only from people
            who agreed to be counted, so the number of timings is lower than the number of opens;
            the opens themselves are complete.
        </p>
        <p class="secblurb">
            Day-by-day figures are kept for {{ config('agentpro.analytics.retention_days') }} days
            in full and as daily totals after that.
        </p>
    </section>
</div>
@endsection
