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
                <a href="{{ route('lister.listings.create') }}" class="btn btn-brand">Add a listing</a>
            @endcan
        </div>
    </div>

    <x-flash />

    @if ($errors->any())
        <div class="alert alert-bad" role="alert">
            {{-- The bag is shared, so the heading has to follow what is in it.
                 "This listing cannot be submitted yet" above an unanswered
                 unlisting prompt sends the lister to fix the wrong thing. --}}
            <strong>
                @if ($errors->hasAny(['outcome', 'reason_code']))
                    That listing has not come off the market:
                @else
                    This listing cannot be submitted yet:
                @endif
            </strong>
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
            <a href="{{ route('verify.show') }}" class="btn btn-deep btn-sm">
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
            <a href="{{ route('scan.schedule', $order) }}" class="btn btn-deep btn-sm">Choose a date</a>
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

                {{-- FR-M13-01. Offered only once a listing has been on the
                     market: a draft has no performance to report, and a link to
                     a screen of zeroes reads as a broken feature. --}}
                @if ($property->published_at)
                    <a href="{{ route('lister.listings.analytics', $property) }}" class="btn btn-ghost btn-sm">
                        Performance
                    </a>
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

                {{--
                    FR-M2-09. A disclosure rather than a button, because there is
                    no safe default for the outcome: this is the one question the
                    lister has to answer for the archive to mean anything, and a
                    single "Unlist" that quietly recorded "other" would empty the
                    archive one convenient click at a time.

                    No JavaScript — <details> is the whole mechanism.
                --}}
                @can('unlist', $property)
                    <details class="unlist">
                        <summary>Unlist</summary>
                        <form method="POST" action="{{ route('lister.listings.unlist', $property) }}">
                            @csrf
                            <p class="unlist-q">Why is it coming off the market?</p>

                            {{-- Ordered by what this listing is: a rental is far
                                 more likely to be let than sold, and the first
                                 option is the one people press. --}}
                            @foreach ($property->intent === 'sale' ? ['sold', 'rented'] : ['rented', 'sold'] as $outcome)
                                <label class="unlist-opt">
                                    <input type="radio" name="outcome" value="{{ $outcome }}" required>
                                    <span>{{ \App\Support\Vocab::CLOSE_OUTCOMES[$outcome] }}</span>
                                </label>
                            @endforeach

                            <label class="unlist-opt">
                                <input type="radio" name="outcome" value="other" required>
                                <span>Other reason</span>
                            </label>

                            <select name="reason_code" class="finput" aria-label="Reason it is coming off the market">
                                <option value="">If other — pick a reason</option>
                                @foreach (\App\Support\Vocab::listerUnpublishReasons() as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>

                            <p class="unlist-note">
                                Sold and rented listings move to the public
                                <a href="{{ route('pages.closed') }}">sold and let archive</a>,
                                where the asking price is shown and the agreed price is not.
                                You can put it back on the market afterwards.
                            </p>

                            <button type="submit" class="btn btn-deep btn-sm">Take it off the market</button>
                        </form>
                    </details>
                @endcan

                @can('relist', $property)
                    <form method="POST" action="{{ route('lister.listings.relist', $property) }}">
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-sm">Back on the market</button>
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
