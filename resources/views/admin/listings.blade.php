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
    {{-- Shown only to somebody who has listings of their own, so it is not a
         control that does nothing for most of the people on this screen. --}}
    @if ($mineCount > 0)
        <label class="checkline nowrap">
            <input type="checkbox" name="mine" value="1" @checked(request()->boolean('mine'))>
            <span>Only mine ({{ $mineCount }})</span>
        </label>
    @endif
    <button type="submit" class="btn btn-brand btn-sm">Filter</button>
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
                    <a href="{{ route('admin.review', $p) }}" class="btn btn-brand btn-sm">Review</a>
                @endif

                {{--
                    Edit on your own listings only. The form has an ability of
                    its own — PropertyPolicy::rewrite, excluded from the staff
                    grant — because changing what somebody's advert says, under
                    their name, is authorship rather than moderation. Taking a
                    listing down is the moderation answer, and it happens on
                    the review screen with a reason recorded. Staff keep
                    `update`, so the photographs and the analytics are still
                    theirs to work with.

                    Asked of the policy rather than compared by hand, so this
                    cannot drift from what the request would actually allow. It
                    also drops the link on your own sold or rented listing,
                    which `update` refuses for everybody.

                    Delete is the exception, on drafts and nothing else. A draft is the
                    one state that has never been public and can have nothing
                    paid against it — the controller refuses both — so what is
                    lost is work nobody outside has seen. Anything submitted is
                    unlisted instead, which keeps the record of what happened
                    to it.

                    It stays on everybody's drafts because it is answerable
                    in the way an edit is not: confined to drafts, carrying a
                    reason, and the lister is told. It is a disclosure rather
                    than a button, and the warning names whose draft it is,
                    because this is the one action on the screen that cannot be
                    undone from the audit log that records it.
                --}}
                @can('rewrite', $p)
                    <a href="{{ route('lister.listings.edit', $p) }}" class="btn btn-ghost btn-sm">Edit</a>
                @endcan

                @if ($p->lifecycle_state === \App\Enums\LifecycleState::Draft)
                    <details class="refundbox">
                        <summary class="linkbtn linkbtn-bad">Delete</summary>
                        <form method="POST" action="{{ route('lister.listings.destroy', $p) }}" class="taxform taxform-tight">
                            @csrf @method('DELETE')
                            @if ($p->lister_id === auth()->id())
                                <span class="fhint">This draft and its photographs go for good.</span>
                            @else
                                <span class="fhint">
                                    {{ $p->lister?->name ?? 'This lister' }}’s draft and its photographs go for
                                    good, and they are told that you deleted it.
                                </span>
                                {{-- Optional, and it goes to the lister as well as the
                                     audit log: a reason the platform keeps and the person
                                     affected never sees is a file note, not a reason. --}}
                                <label class="flabel" for="note-{{ $p->id }}">Reason (optional)</label>
                                <input type="text" id="note-{{ $p->id }}" name="note" maxlength="300"
                                       class="finput" placeholder="Duplicate of an existing listing">
                            @endif
                            <button type="submit" class="btn btn-ghost btn-sm">Yes, delete it</button>
                        </form>
                    </details>
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
