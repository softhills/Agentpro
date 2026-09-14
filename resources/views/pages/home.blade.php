@extends('layouts.app')

@section('title', 'Agentpro — verified property in Lagos and Abuja')

@section('content')

<section class="hero">
    {{--
        A photograph of the actual place, not a vector skyline.

        What stood here was five flat polygons that could have been any city on
        earth, on the front door of a product whose entire claim is that what it
        shows you is real. This is the Lekki–Ikoyi link bridge with Ikoyi's
        housing stock behind it — a seeker in Lagos recognises it in under a
        second, which is the whole job of a banner.

        Decorative, so alt is empty: the headline beside it already says what
        the page is, and a screen reader announcing "aerial view of a bridge"
        would add nothing a seeker can act on.

        Two files rather than one. The 760px crop is 102KB against the desktop
        crop's 248KB, and a seeker on a metered Nigerian connection should not
        pay for pixels their phone cannot show (NFR-02). Dimensions are stated
        so the band does not reflow when the photograph arrives.

        Licence: Unsplash, by Tunde Buremo — free for commercial use, no
        permission needed. It is a stand-in. A production Agentpro should carry
        photography it commissioned, and the README says so.
    --}}
    <picture class="heroshot">
        <source media="(max-width:640px)" srcset="{{ asset('img/hero-lagos-760.jpg') }}">
        <img src="{{ asset('img/hero-lagos-1440.jpg') }}" alt=""
             width="1440" height="660" fetchpriority="high" decoding="async">
    </picture>

    <div class="container hero-in">
        <h1>Find a home you can actually trust</h1>
        <p class="lede">Verified listings across Lagos and Abuja — with the full cost of moving in shown before you call anyone.</p>

        <nav class="segs" aria-label="Listing intent">
            <a href="{{ route('search') }}" @if (! request('intent') && ! request('type')) aria-current="page" @endif>All</a>
            <a href="{{ route('search', ['intent' => 'rent']) }}" @if (request('intent') === 'rent') aria-current="page" @endif>Rent</a>
            <a href="{{ route('search', ['intent' => 'sale']) }}" @if (request('intent') === 'sale') aria-current="page" @endif>Buy</a>
            <a href="{{ route('search', ['type' => 'land']) }}" @if (request('type') === 'land') aria-current="page" @endif>Land</a>
        </nav>

        <form class="searchpanel" method="GET" action="{{ route('search') }}">
            <div class="field">
                <label for="q">Location or ///address</label>
                <input type="text" id="q" name="q" placeholder="Lekki Phase 1, Lagos">
            </div>
            <div class="field">
                <label for="type">Property type</label>
                <select id="type" name="type">
                    <option value="">Any type</option>
                    <option value="apartment">Apartment</option>
                    <option value="house">House</option>
                    <option value="land">Land</option>
                </select>
            </div>
            <div class="field">
                <label for="max_price">Max price</label>
                <select id="max_price" name="max_price">
                    <option value="">Any price</option>
                    <option value="5000000">&#8358;5,000,000</option>
                    <option value="12000000">&#8358;12,000,000</option>
                    <option value="50000000">&#8358;50,000,000</option>
                    <option value="250000000">&#8358;250,000,000</option>
                </select>
            </div>
            <button type="submit" class="btn btn-blue">Search</button>
        </form>

        <p class="w3w-hint">Also searchable by what3words — try <b>///plant.chief.maker</b></p>
    </div>
</section>

{{--
    Live figures, not a target and not a claim.

    A landing page saying "thousands of listings" when there are eighty is the
    same overselling this platform exists to stop, and a visitor who counts the
    search results afterwards learns something worse than a small number. The
    fee figure looks like a boast and is actually a description of how
    publishing works: FR-M7-01 blocks a listing without a complete breakdown,
    so anything under 100% here is a bug report.
--}}
@if ($proof['live'] > 0)
    <section class="proofband">
        <div class="container">
            <dl>
                <div>
                    <dt>On the market</dt>
                    <dd>{{ number_format($proof['live']) }}</dd>
                </div>
                <div>
                    <dt>RealSure verified</dt>
                    <dd class="dd-sure">{{ number_format($proof['verified']) }}</dd>
                </div>
                <div>
                    <dt>Verified listers</dt>
                    <dd>{{ number_format($proof['listers']) }}</dd>
                </div>
                <div>
                    <dt>Areas with stock</dt>
                    <dd>{{ $proof['areas'] }}</dd>
                </div>
                <div>
                    <dt>Full cost shown</dt>
                    <dd>100<span>%</span></dd>
                </div>
                {{-- The only figure in the band that leads anywhere, because it
                     is the only one a visitor would want to check: a count of
                     completions is worth nothing if you cannot see them. Hidden
                     at zero rather than printed as a nought — a new marketplace
                     advertising that nothing has sold yet is telling the truth
                     in the least useful possible way. --}}
                @if ($proof['closed'] > 0)
                    <div>
                        <dt>Sold or let</dt>
                        <dd><a href="{{ route('pages.closed') }}">{{ number_format($proof['closed']) }}</a></dd>
                    </div>
                @endif
            </dl>
        </div>
    </section>
@endif

<section class="sec">
    <div class="container">
        <div class="sec-head">
            <h2>Featured properties</h2>
            <p>Every listing below is published by a verified lister and approved before it goes live.</p>
        </div>

        <div class="grid3">
            @forelse ($featured as $property)
                <x-property-card :property="$property" />
            @empty
                <p class="empty" style="grid-column:1/-1">
                    <strong>No published listings yet</strong>
                    Run <code>php artisan migrate:fresh --seed</code> to load the development inventory.
                </p>
            @endforelse
        </div>
    </div>
</section>

{{-- Only areas that actually have something in them. A grid of place names
     leading to empty result pages teaches a visitor that the links do not
     work, which is the lesson risk R9 warns about for the map. --}}
@if ($places->isNotEmpty())
    <section class="sec sec-tint">
        <div class="container">
            <div class="sec-head">
                <h2>Where people are looking</h2>
                <p>Every area with something on the market today, busiest first.</p>
            </div>

            <div class="placegrid">
                @foreach ($places as $place)
                    <a href="{{ route('search', ['area' => $place->slug]) }}" class="placecard">
                        <span class="placecard-name">
                            {{ $place->name }}
                            <em>{{ $place->city }}</em>
                        </span>
                        <span class="placecard-meta">
                            <b>{{ $place->live }}</b> {{ Str::plural('listing', $place->live) }}
                            @if ($place->cheapest)
                                <em>from {{ \App\Support\Money::naira($place->cheapest) }}</em>
                            @endif
                        </span>
                    </a>
                @endforeach
            </div>

            <p class="sec-more"><a href="{{ route('pages.areas') }}">Every area we cover →</a></p>
        </div>
    </section>
@endif

@if ($recent->isNotEmpty())
    <section class="sec">
        <div class="container">
            <div class="sec-head">
                <h2>Just listed</h2>
                <p>Newest on the market. Nothing here appears above — this is the rest of it.</p>
            </div>

            <div class="grid3">
                @foreach ($recent as $property)
                    <x-property-card :property="$property" />
                @endforeach
            </div>

            <p class="sec-more"><a href="{{ route('search') }}">Search everything →</a></p>
        </div>
    </section>
@endif

{{-- Replaces the theme's about-with-video-thumbnails block. It earns the slot by
     explaining RealSure, which is the one thing a first-time visitor has no
     reference for. --}}
<section class="videoband">
    <div class="container vb-in">
        <div>
            <h2>See what RealSure actually checks</h2>
            <p>Two minutes inside a verification — the registry search, the community investigation, and the capture visit that produces the 3D tour.</p>
            <ul class="vb-list">
                <li><x-icon name="check" stroke-width="2.5" />Title verified at the state land registry</li>
                <li><x-icon name="check" stroke-width="2.5" />Community and neighbour investigation</li>
                <li><x-icon name="check" stroke-width="2.5" />On-site capture by an Agentpro technician</li>
            </ul>
            <a href="{{ route('pages.realsure') }}" class="btn btn-blue">How RealSure works</a>
        </div>

        <div class="vbplayer">
            <x-placeholder :seed="4" />
            <span class="vchip"><x-icon name="video" />RealSure explained</span>
            <span class="vdur">2:08</span>
            <button type="button" class="gate">
                <span>
                    <span class="play"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg></span>
                    <strong>Play video</strong>
                    <small>streams on tap · no autoplay</small>
                </span>
            </button>
        </div>
    </div>
</section>

@endsection
