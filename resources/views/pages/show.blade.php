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
                    @if ($property->isRealsureVerified())
                        <span class="sure" style="margin-left:4px"><x-icon name="check" stroke-width="2.5" />REALSURE VERIFIED</span>
                    @endif
                </p>
            </div>
            <div class="acts">
                <button type="button" class="btn btn-ghost btn-sm"><x-icon name="heart" />Save</button>
                <button type="button" class="btn btn-ghost btn-sm">Hide</button>
                <button type="button" class="btn btn-ghost btn-sm">Report</button>
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
        </div>
    </div>
</div>

@endsection
