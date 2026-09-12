@extends('layouts.app')
@section('title', 'Your assignments — Agentpro')
@section('content')
<div class="container dash">
    <div class="dashhead">
        <div><h1>Your assignments</h1><p>Capture visits assigned to you</p></div>
    </div>

    <x-flash />
    <x-form-errors />

    @forelse ($jobs as $job)
        <article class="listrow">
            <div class="listthumb"><x-property-image :asset="$job->property->coverImage()" :seed="$job->property->id" :alt="$job->property->title" rendition="400" /></div>
            <div class="listmain">
                <div class="listtop">
                    <h2>{{ $job->property->title }}</h2>
                    <span class="lstate lstate-{{ $job->state === 'live' ? 'published' : 'submitted' }}">{{ $job->stateLabel() }}</span>
                </div>
                <p class="listmeta"><strong>{{ $job->scheduled_for?->format('D j M, g:ia') ?? 'Unscheduled' }}</strong></p>
                <p class="listmeta">{{ $job->property->address_line }}, {{ $job->property->area?->name }}</p>
                {{-- what3words leads: street addresses here frequently do not resolve,
                     and this is what actually gets a technician to the gate. --}}
                @if ($job->property->what3words)
                    <p class="listmeta"><span class="w3wtag">{{ $job->property->what3words }}</span></p>
                @endif
                <p class="listmeta">
                    Contact: {{ $job->property->lister->name }}
                    @if ($job->property->lister->phone)
                        · <a href="tel:{{ $job->property->lister->phone }}" style="font-weight:700;color:var(--blue-dark)">{{ $job->property->lister->phone }}</a>
                    @endif
                </p>
            </div>
            <div class="listacts">
                @unless ($job->attended_at)
                    <form method="POST" action="{{ route('technician.attend', $job) }}">
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-sm">Arrived</button>
                    </form>
                @endunless
                <details class="capturebox">
                    <summary class="btn btn-blue btn-sm">Attach capture</summary>
                    <form method="POST" action="{{ route('technician.capture', $job) }}" class="stack" style="margin-top:9px">
                        @csrf
                        <input name="capture_reference" class="finput finput-sm" placeholder="Matterport space id" required>
                        <input name="notes" class="finput finput-sm" placeholder="Notes (optional)">
                        <button type="submit" class="btn btn-green btn-sm">Publish tour</button>
                    </form>
                </details>
            </div>
        </article>
    @empty
        <div class="empty"><strong>Nothing assigned</strong>Bookings in your coverage areas will appear here.</div>
    @endforelse
</div>
@endsection
