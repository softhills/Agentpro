@props(['property'])

@php
    use App\Support\Money;

    $unit  = $property->headlineUnit();
    $glyph = $property->cardGlyph();
    $closed = $property->lifecycle_state->isClosed();
@endphp

<article class="card" data-pin="{{ $property->uuid }}">
    <a href="{{ route('property.show', $property) }}" class="media" aria-label="{{ $property->title }}">
        <x-property-image :asset="$property->coverImage()" :seed="$property->id"
                          :alt="$property->title" rendition="800" />

        {{-- Status pill, top-left. --}}
        <span @class(['pill', 'pill-status', 'pill-sale' => $property->intent === 'sale', 'pill-closed' => $closed])>
            @if ($closed)
                {{ $property->lifecycle_state->label() }}
            @else
                {{ $property->intent === 'sale' ? 'For sale' : 'For rent' }}
            @endif
        </span>

        {{-- RealSure sits bottom-left, deliberately away from the status pill so
             the two never read as a single control. --}}
        <span class="mediafoot">
            @if ($property->isRealsureVerified())
                <span class="sure"><x-icon name="check" stroke-width="2.5" />REALSURE</span>
            @endif

            {{-- One media glyph only. A card advertising everything it holds
                 communicates nothing. --}}
            @if ($glyph)
                <span class="glyph">
                    <x-icon :name="match($glyph->value) {
                        'tour_3d' => 'cube',
                        'video' => 'video',
                        'pano_360' => 'globe',
                        'drone' => 'drone',
                        default => 'plan',
                    }" />
                    {{ $glyph->glyph() }}
                </span>
            @endif

            @if ($property->isMultiUnit())
                <span class="units">{{ $property->availableUnitCount() }} of {{ $property->units->count() }} units</span>
            @endif
        </span>
    </a>

    <div class="cardicons">
        <button type="button" aria-label="Save this listing"><x-icon name="heart" /></button>
        <button type="button" aria-label="Compare this listing"><x-icon name="compare" /></button>
    </div>

    <div class="body">
        <p class="price">
            {{ Money::naira($unit?->price) }}
            @if ($unit && $unit->price_period->suffix())
                <span class="per">{{ $unit->price_period->suffix() }}</span>
            @endif
            @if ($unit?->hasPriceDrop())
                <span class="was">{{ Money::naira($unit->previousPrice()) }}</span>
            @endif
        </p>

        {{--
            On the card rather than only on the archive page, so it travels with
            the listing: a closed listing also turns up in search behind
            "include closed" and on its lister's profile, and in both places the
            price above needs the same qualification. Agentpro never sees what a
            property actually went for — only what it was asking.
        --}}
        @if ($closed && $property->closed_at)
            <p class="closednote">{{ $property->lifecycle_state->label() }} {{ $property->closed_at->format('M Y') }} · asking price</p>
        @endif

        <h3><a href="{{ route('property.show', $property) }}">{{ $property->title }}</a></h3>

        <p class="addr"><x-icon name="pin" />{{ $property->area?->name ?? $property->city }}</p>

        @php
            // allTags() folds the derived price_drop in with the lister's own,
            // so this list and the search filter cannot disagree about what a
            // listing carries (FR-M2-04).
            $tags = $property->allTags();
        @endphp
        @if ($tags || $property->finish === 'furnished')
            <p class="tags">
                @foreach ($tags as $tag)
                    <span @class(['tag', 'tag-drop' => $tag === 'price_drop', 'tag-offer' => $tag !== 'price_drop'])>
                        {{ \App\Support\Vocab::TAGS[$tag] }}
                    </span>
                @endforeach
                @if ($property->finish === 'furnished')
                    <span class="tag tag-offer">Furnished</span>
                @endif
            </p>
        @endif

        <p class="stats">
            @if ($unit?->bedrooms)
                <span><x-icon name="bed" />{{ $unit->bedrooms }} {{ Str::plural('bed', $unit->bedrooms) }}</span>
            @endif
            @if ($unit?->bathrooms)
                <span><x-icon name="bath" />{{ $unit->bathrooms }} {{ Str::plural('bath', $unit->bathrooms) }}</span>
            @endif
            {{-- Toilets are counted separately here, which is how listings in this
                 market are actually read. --}}
            @if ($unit?->toilets)
                <span><x-icon name="toilet" />{{ $unit->toilets }} toilets</span>
            @endif
            @if ($unit?->floor_area_sqm)
                <span><x-icon name="area" />{{ number_format($unit->floor_area_sqm) }} m&sup2;</span>
            @endif
        </p>
    </div>

    <div class="cardfoot">
        <span class="avatar">{{ Str::of($property->lister->name)->explode(' ')->take(2)->map(fn ($w) => Str::substr($w, 0, 1))->implode('') }}</span>
        <span class="who">
            {{ $property->lister->name }}
            @if ($property->lister->verification_state === 'verified')
                <x-verified-tick /><span class="sr-only">Verified lister</span>
            @endif
        </span>
        @if ($property->isFresh())
            <span class="fresh">New</span>
        @endif
        <time class="when" datetime="{{ $property->published_at?->toIso8601String() }}">
            {{ $property->published_at?->diffForHumans(short: true) }}
        </time>
    </div>
</article>
