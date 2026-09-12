@props([
    'asset' => null,
    'seed' => 0,
    'rendition' => '800',
    'alt' => '',
    'sizes' => '(max-width: 760px) 100vw, 400px',
])

{{--
    One place decides whether a real photograph or placeholder art is shown, so
    cards, the viewer and the dashboard can never disagree about it.

    Real images are lazy-loaded with a responsive srcset (NFR-02): the browser
    takes the width it needs rather than always the largest, which on a metered
    connection is the difference between a listing costing 300KB and 2MB.
--}}
@php $url = $asset?->url($rendition); @endphp

@if ($url)
    <img
        src="{{ $url }}"
        @if ($asset->srcset()) srcset="{{ $asset->srcset() }}" sizes="{{ $sizes }}" @endif
        alt="{{ $alt }}"
        loading="lazy"
        decoding="async"
        @if ($asset->width && $asset->height) width="{{ $asset->width }}" height="{{ $asset->height }}" @endif
        {{ $attributes->merge(['class' => 'ph']) }}
    >
@else
    <x-placeholder :seed="$seed" />
@endif
