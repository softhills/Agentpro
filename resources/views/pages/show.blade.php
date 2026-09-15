@extends('layouts.app')

@section('title', $property->title.' — '.($property->area?->name ?? $property->city).' — Agentpro')
@section('meta_description', Str::limit(strip_tags($property->description ?? ''), 155))

@push('head')
{{-- FR-M5-08: listing pages are indexable and carry structured data. --}}
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'RealEstateListing',
    'name' => $property->title,
    'url' => route('property.show', $property),
    'datePosted' => $property->published_at?->toIso8601String(),
    'address' => [
        '@type' => 'PostalAddress',
        'streetAddress' => $property->address_line,
        'addressLocality' => $property->city,
        'addressRegion' => $property->state,
        'addressCountry' => 'NG',
    ],
    'geo' => ['@type' => 'GeoCoordinates', 'latitude' => $property->lat, 'longitude' => $property->lng],
    'offers' => $unit ? [
        '@type' => 'Offer',
        'price' => (float) $unit->price,
        'priceCurrency' => 'NGN',
    ] : null,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
@endpush

@section('content')

<nav class="crumbs" aria-label="Breadcrumb">
    <div class="container" style="display:flex;gap:7px;flex-wrap:wrap;align-items:center">
        <a href="{{ route('search', ['intent' => $property->intent]) }}">{{ $property->intent === 'sale' ? 'Buy' : 'Rent' }}</a>
        <i>&rsaquo;</i><a href="{{ route('search', ['q' => $property->city]) }}">{{ $property->city }}</a>
        @if ($property->area)
            <i>&rsaquo;</i><a href="{{ route('search', ['area' => $property->area->slug]) }}">{{ $property->area->name }}</a>
        @endif
        <i>&rsaquo;</i><span>{{ $property->title }}</span>
    </div>
</nav>

<div style="background:var(--white)">
    <div class="container">
        <div class="dtop">
            <div>
                <h1>{{ $property->title }}</h1>
                <p class="meta">
                    <span>{{ $property->address_line }}, {{ $property->area?->name ?? $property->city }}</span>
                    @if ($property->what3words)
                        <span style="color:var(--slate-2)">·</span>
                        <span class="w3w">{{ $property->what3words }}</span>
                    @endif
                    {{--
                        FR-M2-14, and the line HotPads puts directly above the
                        price: "Updated 2 hours ago · Verified listing". It was
                        on the card and missing from the detail page, which is
                        where somebody decides whether a listing is still real.
                    --}}
                    <span style="color:var(--slate-2)">·</span>
                    <span class="freshline">
                        @if ($property->isFresh())<b class="isnew">New</b>@endif
                        Updated {{ ($property->content_updated_at ?? $property->published_at)?->diffForHumans() }}
                    </span>
                    @if ($property->isRealsureVerified())
                        <span class="sure" style="margin-left:4px"><x-icon name="check" stroke-width="2.5" />REALSURE VERIFIED</span>
                    @endif
                </p>
            </div>
            {{--
                These three were buttons that did nothing. The routes, the
                actions and their tests all existed; the listing page reached
                none of them, so Save, Hide and Report were decoration on the
                one screen where a seeker would use them (FR-M9-01, FR-M9-10,
                FR-M6-07).

                Forms rather than fetch: they work with JavaScript off, they
                need no CSRF plumbing of their own, and the state after the post
                is rendered by the server rather than guessed at by the client.
            --}}
            <div class="acts">
                @auth
                    <form method="POST" action="{{ route('interact.save', $property) }}">
                        @csrf
                        <button class="btn btn-sm {{ in_array('save', $mine, true) ? 'btn-green' : 'btn-ghost' }}">
                            <x-icon name="heart" />{{ in_array('save', $mine, true) ? 'Saved' : 'Save' }}
                        </button>
                    </form>

                    <form method="POST" action="{{ route('interact.hide', $property) }}">
                        @csrf
                        <button class="btn btn-ghost btn-sm">
                            {{ in_array('hide', $mine, true) ? 'Unhide' : 'Hide' }}
                        </button>
                    </form>
                @else
                    {{-- FR-M1-03: an account is the price of acting on a
                         listing, not of seeing one. Sending them to sign in
                         beats a button that silently fails. --}}
                    <a href="{{ route('login') }}" class="btn btn-ghost btn-sm"><x-icon name="heart" />Save</a>
                @endauth

                <a href="#report" class="btn btn-ghost btn-sm">Report</a>
            </div>
        </div>

        <x-media-viewer :property="$property" />
    </div>
</div>

<div class="container">
    <div class="dbody">
        <div>
            @if ($property->description)
                <section class="panel">
                    <h2>About this property</h2>
                    <p>{{ $property->description }}</p>
                </section>
            @endif

            @if ($property->amenities->isNotEmpty())
                <section class="panel">
                    <h2><x-icon name="home" />Amenities</h2>
                    <div class="amen">
                        @foreach ($property->amenities as $amenity)
                            <div><x-icon name="check" stroke-width="2.5" />{{ $amenity->name }}</div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($property->titleClaims->isNotEmpty())
                <section class="panel">
                    <h2><x-icon name="doc" />Title &amp; documentation</h2>

                    @foreach ($property->titleClaims as $claim)
                        <div class="titlerow">
                            <span class="nm">{{ $claim->label() }}</span>
                            <span @class(['st', 'st-ok' => $claim->isAvailable(), 'st-prog' => ! $claim->isAvailable()])>
                                {{ $claim->isAvailable() ? 'Available' : 'In progress' }}
                            </span>
                        </div>
                    @endforeach

                    {{-- FR-M6-06. This wording is load-bearing: without it the
                         RealSure badge reads as Agentpro standing behind the
                         title itself. --}}
                    <p class="declaim">
                        <strong>Declared by the lister.</strong> Agentpro makes no representation as to the
                        legal validity of any title unless the title-verification component of RealSure has
                        been completed on this listing. Always instruct your own solicitor.
                    </p>
                </section>
            @endif

            {{--
                FR-M7-07. The PRD calls this "nearly free" because PriceHistory
                already existed to derive the price-drop tag — it was being
                eager-loaded on this page and never rendered.

                It earns its place because a price that has moved twice in a
                month says something a single number cannot: either the lister
                is finding the market, or the listing has been sitting.
            --}}
            @if ($priceHistory->count() > 1)
                <section class="panel">
                    <h2><x-icon name="naira" />Price history</h2>
                    <ol class="pricehist">
                        @foreach ($priceHistory as $i => $point)
                            @php
                                $previous = $priceHistory[$i + 1] ?? null;
                                $delta = $previous ? (float) $point->price - (float) $previous->price : null;
                            @endphp
                            <li>
                                <span class="ph-date">{{ $point->effective_at?->format('j M Y') }}</span>
                                <span class="ph-price">{{ \App\Support\Money::naira($point->price) }}</span>
                                <span class="ph-delta">
                                    @if ($delta === null)
                                        Listed
                                    @elseif ($delta < 0)
                                        <b class="down">↓ {{ \App\Support\Money::naira(abs($delta)) }}</b>
                                    @elseif ($delta > 0)
                                        <b class="up">↑ {{ \App\Support\Money::naira($delta) }}</b>
                                    @else
                                        No change
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif

            @if ($property->realsureRecords->isNotEmpty())
                @php $recorded = $property->realsureRecords->keyBy('component'); @endphp
                <section class="panel">
                    <h2><x-icon name="shield" />What RealSure checked</h2>
                    <div class="surelist">
                        {{--
                            Every component in the vocabulary, not only the rows
                            that happen to exist.

                            An officer records what they did; there is no reason
                            for them to create a row saying "we did not
                            commission a valuation". But to a seeker the absence
                            of a record and an explicit "not done" are the same
                            fact, and showing only the completed ones would turn
                            this panel into a list of ticks — which reads as a
                            full audit and is the opposite of what FR-M6-02
                            asks for. A badge that does not say what was checked
                            is worth nothing, and one that hides what was not
                            checked is worse than nothing.
                        --}}
                        @foreach (\App\Support\Vocab::REALSURE_COMPONENTS as $key => $label)
                            @php
                                $record = $recorded[$key] ?? null;
                                $done = (bool) $record?->completed;
                            @endphp
                            <div @class(['sureitem', 'no' => ! $done])>
                                <x-icon :name="$done ? 'check' : 'minus'"
                                        :stroke-width="$done ? '2.5' : '2'" />
                                {{ $label }}{{ $done ? '' : ' — not commissioned' }}
                                <span class="dt">{{ $record?->completed_on?->format('j M Y') ?? '—' }}</span>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($property->isMultiUnit())
                <section class="panel">
                    <h2><x-icon name="home" />Available units</h2>
                    <div style="overflow-x:auto">
                        <table style="width:100%;border-collapse:collapse;font-family:var(--i);font-size:13.5px">
                            <thead>
                                <tr style="text-align:left;color:var(--slate-2);font-family:var(--m);font-size:10.5px;letter-spacing:.08em;text-transform:uppercase">
                                    <th style="padding:8px 10px 8px 0">Unit</th>
                                    <th style="padding:8px 10px">Beds</th>
                                    <th style="padding:8px 10px">Area</th>
                                    <th style="padding:8px 10px">Status</th>
                                    <th style="padding:8px 0 8px 10px;text-align:right">Price</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($property->units as $u)
                                    <tr style="border-top:1px solid var(--line)">
                                        <td style="padding:9px 10px 9px 0;font-weight:600">{{ $u->label ?? '—' }}</td>
                                        <td style="padding:9px 10px">{{ $u->bedrooms ?? '—' }}</td>
                                        <td style="padding:9px 10px">{{ $u->floor_area_sqm ? number_format($u->floor_area_sqm).' m²' : '—' }}</td>
                                        <td style="padding:9px 10px">
                                            <span @class(['st', 'st-ok' => $u->status === 'available', 'st-prog' => $u->status !== 'available'])>
                                                {{ ucfirst($u->status) }}
                                            </span>
                                        </td>
                                        <td style="padding:9px 0 9px 10px;text-align:right;font-variant-numeric:tabular-nums;font-weight:600">
                                            {{ \App\Support\Money::naira($u->price) }}<span style="color:var(--slate-2)">{{ $u->price_period->suffix() }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>

        <div>
            <x-fee-panel :property="$property" :unit="$unit" />

            {{--
                HotPads calls this "Competition for this rental" and puts the
                same two numbers under it. It is the most useful thing on their
                page that costs nothing to produce — a seeker deciding whether
                to ring today wants to know whether anybody else is.

                M13 already records both, so this is reading data we hold, not
                collecting anything new. It is the same figure the lister sees
                on their own performance screen, so the two cannot tell
                different stories about one listing.
            --}}
            @if ($demand)
                <div class="demand">
                    <h3><x-icon name="chart" />Interest this week</h3>
                    <p>
                        Opened <b>{{ $demand['views'] }}</b> times
                        @if ($demand['contacts'] > 0)
                            and contacted <b>{{ $demand['contacts'] }}</b>
                            {{ Str::plural('time', $demand['contacts']) }}
                        @endif
                        in the last seven days.
                    </p>
                </div>
            @endif
        </div>
    </div>

    {{-- Where it is ------------------------------------------------------- --}}

    <section class="panel dmap-panel">
        <h2><x-icon name="pin" />Where it is</h2>
        <p class="fhint" style="margin-bottom:12px">
            @if ($property->what3words)
                Street addressing in Lagos and Abuja is unreliable, so every listing carries a
                three-word address as well: <span class="w3w">{{ $property->what3words }}</span>
            @else
                {{ $property->address_line }}, {{ $property->area?->name ?? $property->city }}
            @endif
        </p>
        {{-- Same Leaflet build as the search map: one raster tile renderer for
             the whole site rather than a second map stack on this page. --}}
        <div id="detail-map" class="dmap"
             data-config="{{ json_encode([
                 'lat' => (float) $property->lat,
                 'lng' => (float) $property->lng,
                 'label' => $property->title,
                 'tileUrl' => config('agentpro.map.tile_url'),
                 'attribution' => config('agentpro.map.attribution'),
                 'maxZoom' => (int) config('agentpro.map.max_zoom'),
             ]) }}"></div>
        <p class="fhint" style="margin-top:10px">
            The pin is the location the lister gave. Confirm it on the ground before you pay
            anybody anything.
        </p>
    </section>

    {{-- Report ------------------------------------------------------------ --}}

    {{--
        HotPads ends every listing with "Spot something off? Good catch. Flag it
        and help keep listings legit." — a fraud route at the bottom of the page
        as well as a control at the top, because somebody who has just read the
        whole listing is exactly who notices something wrong.

        FR-M6-07, and the PRD folds "scam and fraud warnings surfaced on
        listings" into it.
    --}}
    <section class="panel reportpanel" id="report">
        <h2><x-icon name="shield" />Spot something wrong?</h2>
        <p class="fhint">
            Every listing here is approved by a person before it goes live and the lister's
            identity is verified — but nobody catches everything, and you are looking at this
            one more closely than we did. Reports flagged as fraudulent are reviewed within
            {{ config('agentpro.sla.fraud_report_hours') }} working hours.
        </p>

        @auth
            @if (in_array('report', $mine, true))
                <p class="allclear"><x-icon name="check" stroke-width="2.5" />
                    You have reported this listing. It is with our moderators.</p>
            @else
                <form method="POST" action="{{ route('interact.report', $property) }}" class="reportform">
                    @csrf
                    <div class="fieldset">
                        <label class="flabel" for="reason_code">What is wrong</label>
                        <select id="reason_code" name="reason_code" class="finput" required>
                            <option value="">Choose a reason</option>
                            @foreach (\App\Support\Vocab::REPORT_REASONS as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="fieldset">
                        <label class="flabel" for="report_note">Anything else (optional)</label>
                        <textarea id="report_note" name="note" class="finput" rows="3" maxlength="1000"
                                  placeholder="The photos are of a different building."></textarea>
                    </div>
                    <button class="btn btn-ghost btn-sm">Report this listing</button>
                </form>
            @endif
        @else
            <p class="fhint">
                <a href="{{ route('login') }}">Sign in</a> to report it — a report goes to a
                moderator, so we need to know who sent it.
            </p>
        @endauth
    </section>
</div>

@endsection

@push('head')
<link rel="stylesheet" href="{{ \App\Support\Asset::url('vendor/leaflet/leaflet.css') }}">
@endpush

@push('scripts')
<script src="{{ \App\Support\Asset::url('vendor/leaflet/leaflet.js') }}"></script>
<script src="{{ \App\Support\Asset::url('js/detail-map.js') }}" defer></script>
@endpush
