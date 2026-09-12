{{--
    The result list, extracted so the map can swap it as HTML when the viewport
    moves. Keeping it server-rendered means one set of card markup rather than a
    second copy maintained in JavaScript.
--}}
<div class="reshead">
    <h2 class="reshead-title">
        {{ $results->total() }} {{ Str::plural('listing', $results->total()) }}
        <span class="cnt">in this area</span>
    </h2>
</div>

<div class="reslist">
    @forelse ($results as $property)
        <x-property-card :property="$property" />
    @empty
        <p class="empty" style="grid-column:1/-1">
            <strong>Nothing here yet</strong>
            Zoom out, move the map, or widen a filter.
        </p>
    @endforelse
</div>

@if ($results->hasPages())
    <div style="margin-top:18px">{{ $results->links() }}</div>
@endif
