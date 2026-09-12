@extends('layouts.app')

@section('title', 'Property search — Agentpro')

@section('content')

{{-- HotPads information architecture in the RealPress skin: a persistent filter
     bar, a result list bound to the map viewport, and card/pin pairing. --}}
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
                <input type="checkbox" name="realsure" value="1" @checked(request('realsure')) style="accent-color:#fff">
                RealSure only
            </label>

            <label @class(['fsel', 'on' => request('has_video')])>
                <input type="checkbox" name="has_video" value="1" @checked(request('has_video')) style="accent-color:#fff">
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
            <h1>{{ request('q') ?: 'All areas' }}</h1>
            <span class="cnt">{{ $results->total() }} verified {{ Str::plural('listing', $results->total()) }}</span>
            <div class="right">
                <form method="GET" style="display:contents">
                    @foreach (request()->except('sort', 'page') as $k => $v)
                        <input type="hidden" name="{{ $k }}" value="{{ is_array($v) ? implode(',', $v) : $v }}">
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

        {{-- FR-M5-07: promoted to R1. This prompt is the loop that brings a
             seeker back before they have found anything. --}}
        <x-flash />

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
                    {{-- The criteria travel as the current query string, so what
                         gets saved is exactly what is on screen. --}}
                    @foreach (request()->query() as $key => $value)
                        @if (is_array($value))
                            @foreach ($value as $item)
                                <input type="hidden" name="{{ $key }}[]" value="{{ $item }}">
                            @endforeach
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

        <div class="reslist">
            @forelse ($results as $property)
                <x-property-card :property="$property" />
            @empty
                <p class="empty" style="grid-column:1/-1">
                    <strong>Nothing matches yet</strong>
                    Try widening the price range, or clearing a filter.
                </p>
            @endforelse
        </div>

        <div style="margin-top:18px">{{ $results->links() }}</div>
    </div>

    {{-- Map pane. The real map library replaces this SVG; the pin markup, the
         pairing behaviour and the pins endpoint are already the production
         contract. --}}
    <div class="mapwrap">
        <svg class="base" viewBox="0 0 500 600" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
            <rect width="500" height="600" fill="#EDF1F6"/>
            <path d="M0 430 Q120 400 240 432 T500 418 L500 600 L0 600Z" fill="#BBD4E8"/>
            <g stroke="#DCE3EC" stroke-width="14" fill="none" stroke-linecap="round">
                <path d="M-10 120 H510"/><path d="M-10 250 H510"/><path d="M-10 360 H510"/>
                <path d="M90 -10 V430"/><path d="M250 -10 V440"/><path d="M395 -10 V424"/>
            </g>
            <g stroke="#FFFFFF" stroke-width="9" fill="none" stroke-linecap="round">
                <path d="M-10 120 H510"/><path d="M-10 250 H510"/><path d="M-10 360 H510"/>
                <path d="M90 -10 V430"/><path d="M250 -10 V440"/><path d="M395 -10 V424"/>
            </g>
            <g fill="#E2E8F0">
                <rect x="110" y="140" width="52" height="42" rx="3"/><rect x="176" y="140" width="58" height="42" rx="3"/>
                <rect x="110" y="196" width="120" height="38" rx="3"/><rect x="272" y="140" width="100" height="90" rx="3"/>
                <rect x="110" y="268" width="58" height="70" rx="3"/><rect x="182" y="268" width="52" height="70" rx="3"/>
                <rect x="272" y="268" width="110" height="70" rx="3"/><rect x="410" y="140" width="70" height="90" rx="3"/>
            </g>
        </svg>

        <span class="maplabel" style="left:26px;top:180px">Lagos</span>
        <span class="maplabel" style="left:214px;top:452px;color:#7C93AC">Lagoon</span>

        <button type="button" class="drawbtn"><x-icon name="draw" />Draw area</button>
        <div class="mapctl">
            <button type="button" aria-label="Zoom in">+</button>
            <button type="button" aria-label="Zoom out">&minus;</button>
        </div>

        @foreach ($pins as $i => $pin)
            <button type="button" class="mappin" data-pin="{{ $pin['id'] }}"
                    style="left:{{ 18 + (($i * 23) % 62) }}%;top:{{ 20 + (($i * 31) % 58) }}%">
                {{ \App\Support\Money::naira($pin['price'], compact: true) }}
            </button>
        @endforeach
    </div>
</div>

@push('scripts')
<script>
(function () {
    // Card <-> pin pairing. The interaction that makes a two-pane search read as
    // one thing rather than two.
    function link(on) {
        return function (event) {
            var id = event.currentTarget.dataset.pin;

            document.querySelectorAll('[data-pin="' + CSS.escape(id) + '"]').forEach(function (node) {
                if (node.classList.contains('mappin')) {
                    node.classList.toggle('act', on);
                } else {
                    node.style.boxShadow = on
                        ? '0 0 0 2px #3E57E3, 0 8px 24px rgba(31,42,78,.18)'
                        : '';
                }
            });
        };
    }

    document.querySelectorAll('[data-pin]').forEach(function (node) {
        node.addEventListener('mouseenter', link(true));
        node.addEventListener('mouseleave', link(false));
        node.addEventListener('focus', link(true));
        node.addEventListener('blur', link(false));
    });
})();
</script>
@endpush

@endsection
