@extends('layouts.admin')

@section('title', 'Funnels — Agentpro admin')
@section('admin_title', 'Funnels')
@section('admin_lede', 'Where people fall out, on both sides of the marketplace. The dashboard says whether the business is working; this says where it stops working.')

@section('admin_content')
{{-- Seeker ------------------------------------------------------------------ --}}

<section class="panel">
    <h2><x-icon name="search" />The seeker funnel</h2>
    <p class="panelhint">
        Last {{ $seeker['days'] }} days. Search and listing views are counted for everyone;
        nothing here needs a cookie, because a total is not personal data. These are steps
        rather than a strict funnel — a listing can be opened from Google, a shared link or
        a saved-search alert without any search on this site — so more listings than searches
        is normal rather than a fault.
    </p>

    @include('partials.funnel', [
        'steps' => $seeker['steps'],
        'arrivals' => 'listings are also opened from Google, shared links and alerts',
    ])

    <div class="metricgrid metricgrid-tight" style="margin-top:18px">
        <x-metric label="Listing → contact" caption="objective O5"
                  :value="$seeker['contact_rate'] === null ? '—' : $seeker['contact_rate'].'%'"
                  :alert="$seeker['contact_rate'] !== null && $seeker['contact_rate'] < $seeker['target_rate']"
                  :note="'target '.$seeker['target_rate'].'%'" />

        <x-metric label="Searches finding nothing" caption="where supply is missing"
                  :value="$seeker['empty_searches']['share'] === null ? '—' : $seeker['empty_searches']['share'].'%'"
                  :alert="($seeker['empty_searches']['share'] ?? 0) > 20"
                  :note="number_format($seeker['empty_searches']['empty']).' of '.number_format($seeker['empty_searches']['searches']).' searches'" />

        <x-metric label="Time spent in a 3D tour" caption="objective O2"
                  :value="$dwell['median'] === null ? '—' : $dwell['median'].'s'"
                  :alert="$dwell['median'] !== null && $dwell['median'] < $dwell['target']"
                  :note="$dwell['readings'] === 0
                      ? 'no timings yet'
                      : 'median of '.number_format($dwell['readings']).' timings · target '.$dwell['target'].'s'" />

        <x-metric label="Agreed to be counted" caption="cookie consent"
                  :value="$seeker['consent']['share'] === null ? '—' : $seeker['consent']['share'].'%'"
                  :note="'of '.number_format($seeker['consent']['events']).' events in the window'" />
    </div>
</section>

{{-- The honesty panel. This is the one that stops the figures above being
     misread, so it sits between the two funnels rather than at the bottom. --}}

<section class="panel">
    <h2><x-icon name="shield" />What these numbers can and cannot tell you</h2>
    <p class="panelhint">
        Two different kinds of figure are on this page, and treating them as one would be
        the easiest mistake to make with it.
    </p>

    <ul class="plainlist">
        <li>
            <span>
                <b>Counts are complete.</b>
                <span class="sub">
                    Searches, listing views, enquiries and tour opens are recorded for every
                    visitor, with no identifier attached. Known bots are excluded, and a
                    reload does not count twice.
                </span>
            </span>
        </li>
        <li>
            <span>
                <b>Journeys cover {{ $seeker['consent']['share'] === null ? 'nobody yet' : $seeker['consent']['share'].'%' }} of traffic.</b>
                @php
                    // Built here rather than inline: a Blade directive glued to
                    // the end of a word is not a directive, and the sentence
                    // reads better assembled than interrupted.
                    $j = $seeker['journeys'];
                    $rate = $j['rate'] === null ? '' : ' — '.$j['rate'].'%';
                @endphp
                <span class="sub">
                    Following one person from a search to an enquiry needs a cookie they agreed
                    to. Of {{ number_format($j['visitors']) }}
                    {{ Str::plural('visitor', $j['visitors']) }} we could follow,
                    {{ number_format($j['reached_detail']) }} opened a listing and
                    {{ number_format($j['reached_contact']) }} went on to make contact{{ $rate }}.
                    That rate describes the people who consented, not the market.
                </span>
            </span>
        </li>
        <li>
            <span>
                <b>The lister funnel is not sampled at all.</b>
                <span class="sub">
                    Every step of it is a timestamp we already keep — a registration, a
                    verification decision, a submission, a publication, an order — so it is
                    complete for all time and unaffected by consent.
                </span>
            </span>
        </li>
    </ul>
</section>

{{-- Lister ------------------------------------------------------------------ --}}

<section class="panel">
    <h2><x-icon name="doc" />The lister funnel</h2>
    <p class="panelhint">
        Everyone who has ever registered as a lister, not a rolling window: whether somebody
        who signed up goes on to publish is a question about the whole population.
    </p>

    {{-- Containment genuinely holds here — you cannot publish without
         submitting, or submit without registering — so every step below shows
         a real drop-off. --}}
    @include('partials.funnel', ['steps' => $lister['steps']])

    <div class="metricgrid metricgrid-tight" style="margin-top:18px">
        <x-metric label="Published → bought a capture" caption="objective O6"
                  :value="$lister['upgrade_rate'] === null ? '—' : $lister['upgrade_rate'].'%'"
                  :alert="$lister['upgrade_rate'] !== null && $lister['upgrade_rate'] < $lister['target_upgrade']"
                  :note="'target '.$lister['target_upgrade'].'% of listers with a live listing'" />
    </div>
</section>

{{-- Demand by area ---------------------------------------------------------- --}}

<section class="taxblock">
    <h2>Where the attention is</h2>
    <p class="panelhint">
        Views per area against the listings available there. An area near the top with few
        listings is demand nobody is serving.
    </p>

    <div class="tablewrap">
    <table class="admintable">
        <thead><tr><th>Area</th><th>City</th><th>Live listings</th><th>Views</th><th>Views per listing</th></tr></thead>
        <tbody>
        @forelse ($areas as $area)
            <tr>
                <td><strong>{{ $area->name }}</strong></td>
                <td>{{ $area->city }}</td>
                <td class="num">{{ number_format($area->listings) }}</td>
                <td class="num">{{ number_format($area->views) }}</td>
                <td class="num">{{ $area->listings > 0 ? round($area->views / $area->listings, 1) : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="fhint">No published listings yet.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</section>
@endsection
