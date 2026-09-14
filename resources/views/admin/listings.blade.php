@extends('layouts.admin')
@section('title', 'Listings — Agentpro admin')
@section('admin_title', 'Listings')
@section('admin_lede', 'Every listing in every state. The review queue shows only what is awaiting a decision.')

@section('admin_content')
@php use App\Support\Money; @endphp

<form method="GET" class="adminfilters">
    <input type="search" name="q" value="{{ request('q') }}" class="finput" placeholder="Title or address">
    <select name="state" class="finput">
        <option value="">Any state</option>
        @foreach (['draft','submitted','under_review','published','rejected','unpublished','expired','sold','rented'] as $s)
            <option value="{{ $s }}" @selected(request('state') === $s)>{{ str_replace('_',' ',$s) }} ({{ $counts[$s] ?? 0 }})</option>
        @endforeach
    </select>
    <button type="submit" class="btn btn-blue btn-sm">Filter</button>
</form>

<div class="tablewrap">
<table class="admintable">
    <thead><tr><th>Listing</th><th>Lister</th><th>State</th><th>Price</th><th>Photos</th><th></th></tr></thead>
    <tbody>
    @forelse ($listings as $p)
        @php $unit = $p->headlineUnit(); @endphp
        <tr>
            <td>
                <strong>{{ $p->title }}</strong>
                <span class="sub">{{ $p->address_line }}, {{ $p->area?->name ?? $p->city }}</span>
            </td>
            <td>
                {{ $p->lister->name }}
                @if ($p->lister->verification_state !== 'verified')
                    <span class="sub">unverified</span>
                @endif
            </td>
            <td><span class="lstate lstate-{{ $p->lifecycle_state->value }}">{{ $p->lifecycle_state->label() }}</span></td>
            <td class="num">{{ Money::naira($unit?->price) }}{{ $unit?->price_period->suffix() }}</td>
            <td class="num {{ $p->photo_count < config('agentpro.media.min_photos') ? 'num-low' : '' }}">{{ $p->photo_count }}</td>
            <td class="rowacts">
                @if (in_array($p->lifecycle_state->value, ['published','sold','rented'], true))
                    <a href="{{ route('property.show', $p) }}" class="btn btn-ghost btn-sm">View</a>
                @endif

                {{--
                    The unlisting controls were reachable only by filtering the
                    moderation queue to "published" and opening the review
                    screen — which is to say, not reachable. This is the screen
                    you land on when you are looking for one specific listing,
                    so the action belongs here.

                    It is a link to the review screen rather than a form on the
                    row, and that is the point: taking a live listing down is a
                    decision that should be made while looking at the listing,
                    with a reason attached. A one-click Unlist on a table row is
                    a mis-click away from removing the wrong property.
                --}}
                @if ($p->lifecycle_state->canBeUnlisted())
                    <a href="{{ route('admin.review', $p) }}" class="btn btn-ghost btn-sm">Unlist</a>
                @elseif ($p->lifecycle_state->canBeRelisted())
                    <a href="{{ route('admin.review', $p) }}" class="btn btn-ghost btn-sm">Relist</a>
                @elseif (in_array($p->lifecycle_state->value, ['submitted','under_review'], true))
                    <a href="{{ route('admin.review', $p) }}" class="btn btn-blue btn-sm">Review</a>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="6" class="fhint">No listings match that filter.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div style="margin-top:16px">{{ $listings->links() }}</div>
@endsection
