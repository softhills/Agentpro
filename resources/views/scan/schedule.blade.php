@extends('layouts.app')

@section('title', 'Choose a capture date — Agentpro')

@section('content')
<div class="container formwrap">
    <h1>Choose a date</h1>
    <p class="secblurb" style="margin:-12px 0 18px">
        {{ $order->property->title }} — {{ $order->property->address_line }},
        {{ $order->property->area?->name }}
    </p>

    <x-flash />
    <x-form-errors />

    <section class="formsec">
        <h2>Available visits <span class="mutedcount">{{ $order->property->area?->name }}</span></h2>

        @if ($slots->isEmpty())
            {{-- FR-M4-07: paid with nothing bookable is the state that must never
                 look like a dead end. The entitlement is held and said to be held. --}}
            <div class="alert alert-warn">
                <strong>No dates open in {{ $order->property->area?->name }} right now</strong>
                <p style="margin:4px 0 0">
                    Your capture is paid for and held against this listing — nothing is lost.
                    We are adding dates weekly and will email you as soon as one opens.
                </p>
            </div>
            <p class="fhint">Reference {{ $order->uuid }}</p>
        @else
            <p class="secblurb">
                Visits run about {{ config('agentpro.scan.slot_hours') }} hours. Someone needs to
                be there to let the technician in.
            </p>

            <form method="POST" action="{{ route('scan.book', $order) }}">
                @csrf
                <div class="slotgrid">
                    @foreach ($slots as $slot)
                        <label class="slot">
                            <input type="radio" name="slot_id" value="{{ $slot->id }}" required>
                            <span>
                                <strong>{{ $slot->startsAt()->format('D j M') }}</strong>
                                <small>{{ $slot->startsAt()->format('g:ia') }}</small>
                                @if ($slot->capacity - $slot->booked === 1)
                                    <em class="slotlast">last one</em>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>

                <button type="submit" class="btn btn-green" style="margin-top:14px">Book this visit</button>
            </form>
        @endif
    </section>
</div>
@endsection
