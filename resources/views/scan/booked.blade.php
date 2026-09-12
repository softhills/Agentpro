@extends('layouts.app')
@section('title', 'Capture booked — Agentpro')
@section('content')
<div class="authwrap">
    <div class="authcard">
        <h1>Capture booked</h1>
        <x-flash />
        <div class="statebox state-verified">
            <span class="statedot"></span>
            <div>
                <strong>{{ $job->scheduled_for->format('l j F Y, g:ia') }}</strong>
                <p>
                    {{ $job->property->address_line }}, {{ $job->property->area?->name }}<br>
                    Status: {{ $job->stateLabel() }}
                </p>
            </div>
        </div>
        <p class="fhint">
            Someone needs to let the technician in. The visit takes roughly two hours for a
            typical three-bedroom flat, and the tour appears on your listing once processing
            finishes.
        </p>
        @if ($job->canReschedule())
            <p class="fhint">You can still change the date up to {{ config('agentpro.scan.reschedule_notice_hours') }} hours beforehand.</p>
        @endif
        <a href="{{ route('lister.dashboard') }}" class="btn btn-blue btn-block">Back to your listings</a>
    </div>
</div>
@endsection
