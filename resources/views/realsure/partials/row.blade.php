{{--
    One listing in the officer's queue.

    Progress is split into verification and production rather than shown as a
    single "4 of 10", because only one of those two moves a listing towards the
    badge — and a row reading 4/10 gives no hint which four they were.
--}}
@php
    $progress = \App\Queries\RealsureQueue::progressFor($property);
@endphp

<a href="{{ route('realsure.record', $property) }}" class="surerow">
    <span class="surerow-main">
        <b>{{ $property->title }}</b>
        <span class="sub">
            {{ $property->area?->name ?? $property->city }} ·
            {{ $property->lister?->name }} ·
            live {{ $property->published_at?->diffForHumans() }}
        </span>
    </span>

    <span class="surerow-prog">
        <span class="progline">
            <em>Verified</em>
            <b class="{{ $progress['verification'] > 0 ? 'okink' : '' }}">
                {{ $progress['verification'] }}/{{ $progress['verification_of'] }}
            </b>
        </span>
        <span class="progline">
            <em>Produced</em>
            <b>{{ $progress['production'] }}/{{ $progress['production_of'] }}</b>
        </span>
    </span>

    <span class="surerow-state">
        @if ($property->isRealsureVerified())
            <span class="ostate ostate-paid">badged</span>
        @elseif ($progress['verification'] + $progress['production'] > 0)
            <span class="ostate ostate-pending">in progress</span>
        @else
            <span class="ostate">not started</span>
        @endif
    </span>
</a>
