@extends('layouts.admin')

@section('title', 'RealSure — '.$property->title)
@section('admin_title', 'RealSure record')
@section('admin_lede', 'Ten components. What was checked, when, by whom, and against what evidence.')

@section('admin_content')
@php
    use App\Support\Vocab;
@endphp

<p class="crumb"><a href="{{ route('realsure.queue') }}">← Back to the queue</a></p>

<x-flash />
<x-form-errors />

{{-- The listing -------------------------------------------------------- --}}

<section class="panel">
    <h2><x-icon name="home" />{{ $property->title }}</h2>
    <p class="panelhint">
        {{ $property->address_line }}, {{ $property->area?->name ?? $property->city }} ·
        listed by {{ $property->lister?->name }} ·
        <a href="{{ route('property.show', $property) }}" target="_blank" rel="noopener">see the public listing</a>
    </p>

    @if ($property->titleClaims->isNotEmpty())
        {{-- Shown here because title verification is the component that matters
             most, and an officer should not have to open another tab to see
             what the lister actually claimed before checking it. --}}
        <p class="panelhint">
            <b>Title declared by the lister:</b>
            @foreach ($property->titleClaims as $claim)
                {{ $claim->label() }}
                <span class="sub">({{ $claim->isAvailable() ? 'available' : 'in progress' }})</span>@if (! $loop->last), @endif
            @endforeach
        </p>
    @endif
</section>

{{-- The badge ---------------------------------------------------------- --}}

<section class="panel {{ $property->isRealsureVerified() ? '' : ($blockers ? 'panel-warn' : '') }}">
    <h2><x-icon name="shield" />The badge</h2>

    @if ($property->isRealsureVerified())
        <p class="panelhint">
            Granted {{ $property->realsure_verified_at->format('j F Y') }}. Seekers see it on the
            card and on the listing, with the completed components underneath.
        </p>

        <details class="refundbox">
            <summary class="linkbtn danger">Remove the badge</summary>
            <form method="POST" action="{{ route('realsure.revoke', $property) }}" class="taxform">
                @csrf
                <div class="fieldset">
                    <label class="flabel" for="why">Why it is coming off</label>
                    <input id="why" name="why" class="finput" required minlength="10" maxlength="255"
                           placeholder="The search report referred to the adjoining plot.">
                    <span class="fhint">
                        Goes to the lister, who paid for this, and onto the audit trail. Write it for
                        somebody reading it in six months.
                    </span>
                </div>
                <button class="btn btn-danger btn-sm">Remove the badge</button>
            </form>
        </details>

    @elseif ($blockers)
        <p class="panelhint">The badge cannot go on this listing yet.</p>
        <ul class="plainlist">
            @foreach ($blockers as $blocker)
                <li><span class="warnink">{{ $blocker }}</span></li>
            @endforeach
        </ul>

    @else
        <p class="panelhint">
            {{ $progress['verification'] }} of {{ $progress['verification_of'] }} verification
            {{ Str::plural('check', $progress['verification']) }} recorded, including title.
            Granting tells the lister and puts the badge on the public listing.
        </p>
        <form method="POST" action="{{ route('realsure.grant', $property) }}">
            @csrf
            <button class="btn btn-green">Grant the RealSure badge</button>
        </form>
    @endif
</section>

{{-- The components ----------------------------------------------------- --}}

@foreach ([
    'Verification' => ['list' => Vocab::REALSURE_VERIFICATION,
        'blurb' => 'What was actually checked. Only these count towards the badge.'],
    'Production' => ['list' => Vocab::REALSURE_PRODUCTION,
        'blurb' => 'Work produced for the listing. Shown to seekers with a date and an officer, but it establishes nothing about the property, so it does not earn the badge on its own.'],
] as $groupName => $group)

    <section class="taxblock">
        <h2>{{ $groupName }}</h2>
        <p class="panelhint">{{ $group['blurb'] }}</p>

        @foreach ($group['list'] as $component)
            @php $record = $records[$component] ?? null; @endphp

            <details class="surecomp" @if ($record?->completed) open @endif>
                <summary>
                    <x-icon :name="$record?->completed ? 'check' : 'minus'"
                            :stroke-width="$record?->completed ? '2.5' : '2'" />
                    <span class="surecomp-name">{{ $components[$component] }}</span>
                    <span class="surecomp-state">
                        @if ($record?->completed)
                            {{ $record->completed_on?->format('j M Y') }}
                            <em>{{ $record->officer?->name ?? 'officer unknown' }}</em>
                        @else
                            not recorded
                        @endif
                    </span>
                </summary>

                <form method="POST" action="{{ route('realsure.component', $property) }}" class="taxform">
                    @csrf
                    <input type="hidden" name="component" value="{{ $component }}">

                    <label class="prefrow">
                        <input type="hidden" name="completed" value="0">
                        <input type="checkbox" name="completed" value="1" @checked($record?->completed)>
                        <span>
                            <strong>Completed</strong>
                            <small>Unticking it withdraws the check and clears the date.</small>
                        </span>
                    </label>

                    <div class="fieldset">
                        <label class="flabel" for="on_{{ $component }}">Completed on</label>
                        <input id="on_{{ $component }}" name="completed_on" type="date" class="finput"
                               max="{{ now()->toDateString() }}"
                               value="{{ $record?->completed_on?->toDateString() ?? now()->toDateString() }}">
                    </div>

                    <div class="fieldset">
                        <label class="flabel" for="ev_{{ $component }}">Evidence reference</label>
                        <input id="ev_{{ $component }}" name="evidence_ref" class="finput" maxlength="255"
                               value="{{ $record?->evidence_ref }}"
                               placeholder="Search report no. LS/2026/04481">
                        <span class="fhint">
                            The thread back to the document. Agentpro does not store the document
                            itself — this is how somebody finds it later.
                        </span>
                    </div>

                    <div class="fieldset">
                        <label class="flabel" for="nt_{{ $component }}">Notes</label>
                        <textarea id="nt_{{ $component }}" name="notes" class="finput" rows="3"
                                  maxlength="2000">{{ $record?->notes }}</textarea>
                        <span class="fhint">Internal. Not shown on the listing.</span>
                    </div>

                    <button class="btn btn-blue btn-sm">Save this check</button>
                </form>
            </details>
        @endforeach
    </section>
@endforeach
@endsection
