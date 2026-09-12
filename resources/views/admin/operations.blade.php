@extends('layouts.admin')

@section('title', 'Coverage & capacity — Agentpro admin')
@section('admin_title', 'Coverage &amp; capacity')
@section('admin_lede', 'Where 3D capture is offered, and how much of it the field team can actually deliver.')

@section('admin_content')

<section class="panel">
    <h2><x-icon name="pin" />Coverage areas</h2>
    <p class="fhint" style="margin-bottom:12px">
        Turning an area on makes the 3D upgrade purchasable there. It is data, not
        configuration in code, so opening a new area needs no deployment (FR-M4-02).
    </p>

    <div class="tablewrap">
    <table class="admintable">
        <thead><tr><th>Area</th><th>City</th><th>Live listings</th><th>Open slots</th><th>3D capture</th></tr></thead>
        <tbody>
        @foreach ($areas as $area)
            @php $cap = $capacity[$area->id] ?? null; @endphp
            <tr>
                <td><strong>{{ $area->name }}</strong></td>
                <td>{{ $area->city }}</td>
                <td class="num">{{ $area->live_count }}</td>
                <td class="num {{ $area->is_scan_coverage && ! ($cap->open ?? 0) ? 'num-low' : '' }}">
                    {{ $cap->open ?? 0 }}
                    @if ($area->is_scan_coverage && ! ($cap->open ?? 0))
                        <span class="sub">nothing bookable</span>
                    @endif
                </td>
                <td>
                    <form method="POST" action="{{ route('admin.areas.coverage', $area) }}" class="inlineform">
                        @csrf @method('PUT')
                        <button type="submit" @class(['btn', 'btn-sm', 'btn-green' => $area->is_scan_coverage, 'btn-ghost' => ! $area->is_scan_coverage])>
                            {{ $area->is_scan_coverage ? 'Open' : 'Closed' }}
                        </button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</section>

<section class="panel">
    <h2><x-icon name="check" />Open capture capacity</h2>
    <p class="fhint" style="margin-bottom:12px">
        Add only what a technician can genuinely service. A calendar that offers more
        than the field team delivers turns the only paid feature into a queue of
        apologies.
    </p>

    <form method="POST" action="{{ route('admin.areas.slots', $areas->firstWhere('is_scan_coverage', true) ?? $areas->first()) }}"
          class="slotform">
        @csrf
        <div class="fieldset">
            <label class="flabel" for="technician_id">Technician</label>
            <select id="technician_id" name="technician_id" class="finput" required>
                @foreach ($technicians as $t)
                    <option value="{{ $t->id }}">{{ $t->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="fieldset">
            <label class="flabel" for="from">From</label>
            <input id="from" name="from" type="date" class="finput" value="{{ now()->addDay()->toDateString() }}" required>
        </div>
        <div class="fieldset">
            <label class="flabel" for="days">Working days</label>
            <input id="days" name="days" type="number" min="1" max="30" value="14" class="finput" required>
        </div>
        <div class="fieldset">
            <span class="flabel">Times</span>
            <label class="checkline"><input type="checkbox" name="times[]" value="09:00" checked><span>09:00</span></label>
            <label class="checkline"><input type="checkbox" name="times[]" value="13:00" checked><span>13:00</span></label>
        </div>
        <button type="submit" class="btn btn-blue">Open slots</button>
    </form>
    <p class="fhint">Sundays are skipped. Slots that already exist are left alone.</p>
</section>

<section class="panel">
    <h2><x-icon name="cube" />Capture visits in flight</h2>
    <div class="tablewrap">
    <table class="admintable">
        <thead><tr><th>When</th><th>Property</th><th>Area</th><th>Technician</th><th>State</th></tr></thead>
        <tbody>
        @forelse ($jobs as $job)
            <tr @class(['overdue' => $job->scheduled_for && $job->scheduled_for->isPast() && in_array($job->state, ['scheduled','rescheduled'], true)])>
                <td class="mono">{{ $job->scheduled_for?->format('j M, g:ia') ?? 'not booked' }}</td>
                <td>{{ $job->property?->title }}</td>
                <td>{{ $job->area?->name ?? '—' }}</td>
                <td>{{ $job->technician?->name ?? 'unassigned' }}</td>
                <td>{{ $job->stateLabel() }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="fhint">No capture visits in flight.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</section>
@endsection
