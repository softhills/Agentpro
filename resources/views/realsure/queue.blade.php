@extends('layouts.admin')

@section('title', 'RealSure — Agentpro')
@section('admin_title', 'RealSure')
@section('admin_lede', 'What Agentpro is willing to assert about a property, and the evidence behind it. A badge that does not say what was checked is worth nothing.')

@section('admin_content')
@php
    use App\Queries\RealsureQueue;
@endphp

<div class="metricgrid metricgrid-tight">
    <x-metric label="Verified share" :value="$summary['share'].'%'"
              :alert="$summary['share'] < $summary['target']"
              :note="$summary['verified'].' of '.$summary['published'].' live listings'"
              caption="objective O1 · target 25%" />
    <x-metric label="Paid, not delivered" :value="$summary['paid']"
              :alert="$summary['paid'] > 0"
              note="RealSure bought and not finished"
              caption="should be zero" />
    <x-metric label="Started" :value="$inProgress->count()"
              note="checks recorded, badge not granted"
              caption="in progress" />
    <x-metric label="Carrying the badge" :value="$verified->count()"
              note="live and verified" caption="revocable below" />
</div>

{{-- Paid and outstanding ------------------------------------------------- --}}

<section class="panel {{ $paid->isNotEmpty() ? 'panel-warn' : '' }}">
    <h2><x-icon name="naira" />Paid for, not delivered</h2>
    <p class="panelhint">
        A RealSure engagement was bought against these listings and no badge has gone on yet.
        This is money taken for work somebody is still waiting for, so it sits above everything
        else on this screen.
    </p>

    @forelse ($paid as $property)
        @include('realsure.partials.row', ['property' => $property])
    @empty
        <p class="allclear"><x-icon name="check" stroke-width="2.5" /> Nothing outstanding.</p>
    @endforelse
</section>

{{-- In progress --------------------------------------------------------- --}}

@if ($inProgress->isNotEmpty())
    <section class="taxblock">
        <h2>Started</h2>
        <p class="panelhint">
            Checks recorded, badge not granted. Either there is work left, or it is ready and
            waiting for somebody to say so.
        </p>
        @foreach ($inProgress as $property)
            @include('realsure.partials.row', ['property' => $property])
        @endforeach
    </section>
@endif

{{-- Verified ------------------------------------------------------------ --}}

@if ($verified->isNotEmpty())
    <section class="taxblock">
        <h2>Carrying the badge</h2>
        <p class="panelhint">
            Listed rather than filed away, because taking a badge off has to be as reachable as
            putting one on. A console that can only grant makes every past mistake permanent.
        </p>
        @foreach ($verified as $property)
            @include('realsure.partials.row', ['property' => $property])
        @endforeach
    </section>
@endif

{{-- Everything else ------------------------------------------------------ --}}

<section class="taxblock">
    <h2>Every other live listing</h2>
    <p class="panelhint">
        Nothing recorded against these yet. Search by title, street or city to start one.
    </p>

    <form method="GET" class="inlineform" style="margin-bottom:14px">
        <input name="q" class="finput finput-sm" value="{{ $term }}"
               placeholder="Title, street or city" style="min-width:220px">
        <button class="btn btn-ghost btn-sm">Search</button>
        @if ($term)
            <a href="{{ route('realsure.queue') }}" class="linkbtn">Clear</a>
        @endif
    </form>

    @forelse ($untouched as $property)
        @include('realsure.partials.row', ['property' => $property])
    @empty
        <p class="fhint">{{ $term ? 'Nothing matches that.' : 'Every live listing has been started.' }}</p>
    @endforelse

    {{ $untouched->links() }}
</section>
@endsection
