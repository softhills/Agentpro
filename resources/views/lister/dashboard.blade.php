@extends('layouts.app')

@section('title', 'Your listings — Agentpro')

@section('content')
@php use App\Support\Money; @endphp

<div class="container dash">

    <div class="dashhead">
        <div>
            <h1>Your listings</h1>
            <p>{{ $user->categoryLabel() }} · {{ $user->name }}</p>
        </div>
        <div class="dashactions">
            <a href="{{ route('lister.payouts') }}" class="btn btn-ghost">Getting paid</a>
            @can('create', App\Models\Property::class)
                <a href="{{ route('lister.listings.create') }}" class="btn btn-blue">Add a listing</a>
            @endcan
        </div>
    </div>

    <x-flash />

    @if ($errors->any())
        <div class="alert alert-bad" role="alert">
            <strong>This listing cannot be submitted yet:</strong>
            <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    {{-- FR-M1-05: the gate, stated once at the top rather than as a surprise on
         the submit button. --}}
    @unless ($user->isVerified())
        <div class="alert alert-warn">
            <strong>
                @if ($user->verification_state === 'pending')
                    Verification in progress
                @else
                    Verify your identity to publish
                @endif
            </strong>
            <p style="margin:4px 0 10px">
                You can build drafts now. Submitting for review needs a verified account.
            </p>
            <a href="{{ route('verify.show') }}" class="btn btn-navy btn-sm">
                {{ $user->verification_state === 'pending' ? 'Check status' : 'Verify now' }}
            </a>
        </div>
    @endunless

    {{-- FR-M4-07: a paid capture with no date booked must never sit silently.
         It is money taken for something not yet delivered, so it leads. --}}
    @foreach ($unredeemed as $order)
        <div class="alert alert-warn">
            <strong>Your 3D capture is paid for and waiting</strong>
            <p style="margin:4px 0 10px">
                {{ $order->property?->title }} — choose a visit date whenever you are ready.
                Nothing expires.
            </p>
            <a href="{{ route('scan.schedule', $order) }}" class="btn btn-navy btn-sm">Choose a date</a>
        </div>
    @endforeach

    <div class="tiles">
        <div class="tile"><span class="n">{{ $counts['published'] }}</span><span class="l">Published</span></div>
        <div class="tile"><span class="n">{{ $counts['review'] }}</span><span class="l">In review</span></div>
        <div class="tile"><span class="n">{{ $counts['draft'] }}</span><span class="l">Drafts</span></div>
        <div @class(['tile', 'tile-warn' => $counts['expiring'] > 0])>
            <span class="n">{{ $counts['expiring'] }}</span><span class="l">Expiring in 7 days</span>
        </div>
    </div>

    @forelse ($properties as $property)
        @php
            $unit = $property->headlineUnit();
            $state = $property->lifecycle_state;
        @endphp

        <article class="listrow">
            <div class="listthumb"><x-property-image :asset="$property->coverImage()" :seed="$property->id" :alt="$property->title" rendition="400" /></div>

            <div class="listmain">
                <div class="listtop">
                    <h2>{{ $property->title }}</h2>
                    <span class="lstate lstate-{{ $state->value }}">{{ $state->label() }}</span>
                    @if ($property->isRealsureVerified())
                        <span class="sure"><x-icon name="check" stroke-width="2.5" />REALSURE</span>
                    @endif
                </div>

                <p class="listmeta">
                    {{ $property->address_line }}, {{ $property->area?->name ?? $property->city }}
                    · {{ Money::naira($unit?->price) }}{{ $unit?->price_period->suffix() }}
                    @if ($property->isMultiUnit())
                        · {{ $property->availableUnitCount() }} of {{ $property->units->count() }} units
                    @endif
                </p>

                {{-- FR-M2-08: days remaining, not just a date. A date alone
                     prompts nobody to renew. --}}
                @if ($property->daysRemaining !== null && $state->value === 'published')
                    <p @class(['listexpiry', 'soon' => $property->daysRemaining <= 7])>
                        @if ($property->daysRemaining < 0)
                            Expired {{ abs($property->daysRemaining) }} days ago
                        @elseif ($property->daysRemaining === 0)
                            Expires today
                        @else
                            {{ $property->daysRemaining }} days of display left
                        @endif
                        · until {{ $property->expires_at->format('j M Y') }}
                    </p>
                @endif

                @if ($property->rejection_note && $state->value === 'rejected')
                    <p class="listreject"><strong>Returned by review:</strong> {{ $property->rejection_note }}</p>
                @endif

                {{-- Shown inline, so a lister knows what is blocking a draft
                     before they press submit rather than after. --}}
                @if ($property->blockers)
                    <details class="blockers">
                        <summary>{{ count($property->blockers) }} {{ Str::plural('item', count($property->blockers)) }} before this can be submitted</summary>
                        <ul>@foreach ($property->blockers as $b)<li>{{ $b }}</li>@endforeach</ul>
                    </details>
                @endif
            </div>

            <div class="listacts">
                @can('update', $property)
                    <a href="{{ route('lister.listings.edit', $property) }}" class="btn btn-ghost btn-sm">Edit</a>
                @endcan

                @if (in_array($state->value, ['published', 'sold', 'rented'], true))
                    <a href="{{ route('property.show', $property) }}" class="btn btn-ghost btn-sm">View</a>
                @endif

                @if ($property->scanOffer)
                    <a href="{{ route('scan.offer', $property) }}" class="btn btn-ghost btn-sm">Add 3D tour</a>
                @endif

                @can('submit', $property)
                    <form method="POST" action="{{ route('lister.listings.submit', $property) }}">
                        @csrf
                        <button type="submit" class="btn btn-green btn-sm"
                                @disabled($property->blockers)>Submit for review</button>
                    </form>
                @endcan
            </div>
        </article>
    @empty
        <div class="empty">
            <strong>No listings yet</strong>
            @can('create', App\Models\Property::class)
                Add your first listing — you can save it as a draft and finish it later.
            @else
                This account is set up for browsing. Contact support to switch to a listing account.
            @endcan
        </div>
    @endforelse
</div>
@endsection
