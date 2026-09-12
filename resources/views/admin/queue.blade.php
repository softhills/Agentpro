@extends('layouts.app')

@section('title', 'Moderation queue — Agentpro')

@section('content')
@php use App\Support\Money; @endphp

<div class="container dash">
    <div class="dashhead">
        <div>
            <h1>Moderation queue</h1>
            <p>Decisions within {{ $slaHours }} working hours · oldest first</p>
        </div>
    </div>

    <x-flash />

    <nav class="qfilters" aria-label="Queue filter">
        @foreach ([
            'awaiting' => 'Awaiting review ('.$counts['awaiting'].')',
            'published' => 'Published ('.$counts['published'].')',
            'rejected' => 'Returned ('.$counts['rejected'].')',
            'unpublished' => 'Unpublished',
        ] as $key => $label)
            <a href="{{ route('admin.queue', ['state' => $key]) }}"
               @class(['qfilter', 'on' => $filter === $key])>{{ $label }}</a>
        @endforeach
    </nav>

    @forelse ($properties as $property)
        @php $unit = $property->headlineUnit(); @endphp

        <article @class(['listrow', 'sla-breach' => $property->breachesSla])>
            <div class="listthumb"><x-placeholder :seed="$property->id" /></div>

            <div class="listmain">
                <div class="listtop">
                    <h2>{{ $property->title }}</h2>
                    <span class="lstate lstate-{{ $property->lifecycle_state->value }}">
                        {{ $property->lifecycle_state->label() }}
                    </span>
                    @if ($property->breachesSla)
                        <span class="slaflag">SLA breached</span>
                    @endif
                </div>

                <p class="listmeta">
                    {{ $property->address_line }}, {{ $property->area?->name ?? $property->city }}
                    · {{ Money::naira($unit?->price) }}{{ $unit?->price_period->suffix() }}
                    · {{ $property->photo_count }} {{ Str::plural('photo', $property->photo_count) }}
                </p>

                <p class="listmeta">
                    {{ $property->lister->name }}
                    @if ($property->lister->verification_state === 'verified')
                        <x-verified-tick style="width:12px;height:12px;color:var(--sure);vertical-align:-1px" />
                    @else
                        <span class="unverif">unverified lister</span>
                    @endif
                    @if ($property->waitingHours !== null)
                        · waiting {{ $property->waitingHours }}h
                    @endif
                </p>
            </div>

            <div class="listacts">
                <a href="{{ route('admin.review', $property) }}" class="btn btn-blue btn-sm">Review</a>
            </div>
        </article>
    @empty
        <div class="empty">
            <strong>Nothing awaiting review</strong>
            Submitted listings appear here, oldest first.
        </div>
    @endforelse

    <div style="margin-top:18px">{{ $properties->links() }}</div>
</div>
@endsection
