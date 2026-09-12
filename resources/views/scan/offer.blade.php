@extends('layouts.app')
@section('title', 'Add a 3D tour — Agentpro')
@section('content')
<div class="container formwrap">
    <a href="{{ route('lister.listings.edit', $property) }}" class="backlink">&larr; Back to the listing</a>
    <h1>Add a 3D tour</h1>
    <x-flash />
    <x-form-errors />

    <section class="formsec">
        <h2>{{ $property->title }}</h2>
        <p class="secblurb">{{ $property->address_line }}, {{ $property->area?->name ?? $property->city }}</p>

        @if ($reasons)
            {{-- FR-M4-03: say why, and offer the waiting list rather than a dead end. --}}
            <div class="alert alert-warn" style="margin:10px 0 0">
                <strong>Not available for this listing</strong>
                <ul>@foreach ($reasons as $r)<li>{{ $r }}</li>@endforeach</ul>
            </div>
            @if ($property->area && ! $property->area->is_scan_coverage)
                <p class="fhint">We are adding areas steadily. Tell us you are interested and we will let you know when {{ $property->area->name }} opens.</p>
                <button type="button" class="btn btn-ghost" disabled>Register interest</button>
                <p class="fhint">Coming with the notifications build.</p>
            @endif
        @else
            <ul class="vb-list" style="margin:6px 0 16px">
                <li><x-icon name="check" stroke-width="2.5" style="color:var(--green)" />An Agentpro technician visits and captures the property</li>
                <li><x-icon name="check" stroke-width="2.5" style="color:var(--green)" />Walkable 3D tour embedded on your listing</li>
                <li><x-icon name="check" stroke-width="2.5" style="color:var(--green)" />Marked as an Agentpro capture, not a phone video</li>
            </ul>

            <div class="pricebox">
                <div>
                    <span class="pb-amount">{{ $price }}</span>
                    <span class="pb-note">one-off · includes the visit and processing</span>
                </div>
                <form method="POST" action="{{ route('scan.checkout', $property) }}">
                    @csrf
                    <button type="submit" class="btn btn-green">Pay and choose a date</button>
                </form>
            </div>
            <p class="fhint">Card, bank transfer or USSD. You choose the visit date straight after paying.</p>
        @endif
    </section>
</div>
@endsection
