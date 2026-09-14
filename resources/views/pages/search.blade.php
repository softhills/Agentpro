@extends('layouts.app')

@section('title', 'Property search — Agentpro')

@section('content')

{{--
    HotPads information architecture in the RealPress skin: a persistent filter
    bar, a result list bound to the map viewport, and card/pin pairing.

    The wrapper exists so the split can fill whatever the filter bar leaves.
    That bar wraps — 105px at common widths, more when the chips run to three
    lines — so subtracting a guessed height from the viewport put the bottom of
    the map, and the OpenStreetMap credit sitting on it, below the fold.
--}}
<div class="searchpane">
<div class="filterbar">
    <div class="container">
        <form method="GET" action="{{ route('search') }}">
            <label class="fsel wide">
                <x-icon name="search" style="width:14px;height:14px" />
                <span class="sr-only">Search location</span>
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Area, estate or street" style="flex:1">
            </label>

            <label @class(['fsel', 'on' => request('intent')])>
                <span class="sr-only">Intent</span>
                <select name="intent">
                    <option value="">Rent or buy</option>
                    <option value="rent" @selected(request('intent') === 'rent')>Rent</option>
                    <option value="sale" @selected(request('intent') === 'sale')>Buy</option>
                </select>
            </label>

            <label @class(['fsel', 'on' => request('type')])>
                <span class="sr-only">Property type</span>
                <select name="type">
                    <option value="">Type</option>
                    <option value="apartment" @selected(request('type') === 'apartment')>Apartment</option>
                    <option value="house" @selected(request('type') === 'house')>House</option>
                    <option value="land" @selected(request('type') === 'land')>Land</option>
                </select>
            </label>

            {{-- FR-M5-03 "property status". Sits next to Type because the two
                 answer the same question — what kind of thing am I buying —
                 and a seeker filtering for land is rarely after off-plan. --}}
            <label @class(['fsel', 'on' => request('build_status')])>
                <span class="sr-only">Property status</span>
                <select name="build_status">
                    <option value="">Status</option>
                    <option value="fully_built" @selected(request('build_status') === 'fully_built')>Fully built</option>
                    <option value="under_construction" @selected(request('build_status') === 'under_construction')>Under construction</option>
                </select>
            </label>

            <label @class(['fsel', 'on' => request('beds')])>
                <span class="sr-only">Bedrooms</span>
                <select name="beds">
                    <option value="">Beds</option>
                    @for ($i = 1; $i <= 5; $i++)
                        <option value="{{ $i }}" @selected((int) request('beds') === $i)>{{ $i }}+</option>
                    @endfor
                </select>
            </label>

            <label @class(['fsel', 'on' => request('max_price')])>
                <span class="sr-only">Maximum price</span>
                <select name="max_price">
                    <option value="">Max price</option>
                    <option value="5000000" @selected(request('max_price') == 5000000)>&#8358;5M</option>
                    <option value="12000000" @selected(request('max_price') == 12000000)>&#8358;12M</option>
                    <option value="50000000" @selected(request('max_price') == 50000000)>&#8358;50M</option>
                    <option value="250000000" @selected(request('max_price') == 250000000)>&#8358;250M</option>
                </select>
            </label>

            <label @class(['fsel', 'on' => request('realsure')])>
                <input type="checkbox" name="realsure" value="1" @checked(request('realsure')) style="accent-color:var(--on-navy)">
                RealSure only
            </label>

            <label @class(['fsel', 'on' => request('has_video')])>
                <input type="checkbox" name="has_video" value="1" @checked(request('has_video')) style="accent-color:var(--on-navy)">
                Has video
            </label>

            <span class="spacer"></span>
            <button type="submit" class="btn btn-green btn-sm">Apply</button>
        </form>
    </div>
</div>

<div class="split">
    <div class="results">
        <div class="reshead">
            <h1>{{ request('q') ?: 'Property search' }}</h1>
            <div class="right">
                <form method="GET" style="display:contents">
                    @foreach (request()->except('sort', 'page') as $k => $v)
                        @if (is_array($v))
                            @foreach ($v as $item)<input type="hidden" name="{{ $k }}[]" value="{{ $item }}">@endforeach
                        @else
                            <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                        @endif
                    @endforeach
                    <select class="minisel" name="sort" onchange="this.form.submit()">
                        <option value="newest" @selected(request('sort', 'newest') === 'newest')>Newest first</option>
                        <option value="relevance" @selected(request('sort') === 'relevance')>Verified first</option>
                        <option value="price_asc" @selected(request('sort') === 'price_asc')>Price: low to high</option>
                        <option value="price_desc" @selected(request('sort') === 'price_desc')>Price: high to low</option>
                    </select>
                </form>
            </div>
        </div>

        <x-flash />

        {{-- FR-M5-07: the loop that brings a seeker back before they have found
             anything. Sits outside the swappable list so panning the map does
             not make it flicker. --}}
        <div class="savebar">
            <x-icon name="bell" />
            <p>
                Get alerted when something new matches
                <span>{{ collect([
                    request('beds') ? request('beds').'-bed' : null,
                    request('max_price') ? 'under ₦'.number_format((int) request('max_price') / 1_000_000, 0).'M' : null,
                    request('realsure') ? 'RealSure' : null,
                    request('q') ?: null,
                ])->filter()->implode(' · ') ?: 'this search' }}</span>
            </p>
            @auth
                <form method="POST" action="{{ route('saved-searches.store') }}">
                    @csrf
                    @foreach (request()->query() as $key => $value)
                        @if (is_array($value))
                            @foreach ($value as $item)<input type="hidden" name="{{ $key }}[]" value="{{ $item }}">@endforeach
                        @else
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <button type="submit" class="btn btn-blue btn-sm">Save search</button>
                </form>
            @else
                <a href="{{ route('login') }}" class="btn btn-blue btn-sm">Save search</a>
            @endauth
        </div>

        {{-- Swapped wholesale by the map when the viewport moves. --}}
        <div id="search-results">
            @include('partials.result-list', ['results' => $results])
        </div>
    </div>

    {{-- FR-M5-02: the real map. Markers are fetched from /search/pins, which
         returns clusters when zoomed out and individual price pins close in —
         the aggregation happens in SQL so a city-wide viewport does not ship
         thousands of rows to a phone (NFR-02). --}}
    <div class="mapwrap">
        <div id="search-map" data-config="{{ json_encode($mapConfig) }}"></div>
        <noscript>
            <p class="mapnote">
                The map needs JavaScript. The listings are all in the panel beside it.
            </p>
        </noscript>
    </div>
</div>
</div>{{-- /.searchpane --}}

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
@endpush

@push('scripts')
{{-- Leaflet, vendored. Raster tiles do not need a WebGL renderer, so this is
     145KB instead of 918KB and works on devices without WebGL — which is the
     device class NFR-01 is written for. Vendored rather than CDN-loaded because
     search is the product's front door and should not depend on a third party
     being reachable. --}}
<script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
<script src="{{ asset('js/search-map.js') }}"></script>
@endpush

@endsection
