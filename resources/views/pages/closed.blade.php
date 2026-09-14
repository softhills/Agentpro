@extends('layouts.app')

@section('title', 'Sold and let — Agentpro')
@section('meta_description', 'Properties that found a buyer or a tenant on Agentpro, with the month each one came off the market.')

@section('content')
<div class="cpage">
  <header class="chero chero-tight">
    <div class="container">
      <p class="ceyebrow">Archive</p>
      <h1>What has sold and let</h1>
      <p class="clede">
        Every listing here was on the market on Agentpro and has since come off it. A
        marketplace that only ever shows you what is still available tells you nothing about
        whether anything moves — so this is the other half of the picture.
      </p>

      @if ($counts['recent'] > 0)
        {{-- The figure that separates a going concern from a history of one. It
             is a count, not a claim: 30 days, from the closing dates. --}}
        <p class="archivepulse">
          <strong>{{ $counts['recent'] }}</strong>
          {{ Str::plural('property', $counts['recent']) }} came off the market in the last 30 days
        </p>
      @endif

      <nav class="archivetabs" aria-label="Filter by outcome">
        <a href="{{ route('pages.closed') }}"
           @if (! $outcome) aria-current="page" @endif>All <span>{{ $counts['all'] }}</span></a>
        <a href="{{ route('pages.closed', ['outcome' => 'sold']) }}"
           @if ($outcome === 'sold') aria-current="page" @endif>Sold <span>{{ $counts['sold'] }}</span></a>
        <a href="{{ route('pages.closed', ['outcome' => 'rented']) }}"
           @if ($outcome === 'rented') aria-current="page" @endif>Let <span>{{ $counts['rented'] }}</span></a>
      </nav>
    </div>
  </header>

  <div class="container">
    {{--
        Read this before the grid, not after it.

        Agentpro is not a party to any of these transactions: it does not broker
        the sale, it never sees the money, and it therefore does not know what
        any of these properties actually went for. A wall of "SOLD
        ₦85,000,000" would read better and would be a claim nobody here is in a
        position to make. So the figure shown is the asking price, said out
        loud, and the outcome is attributed to the person who reported it.
    --}}
    <p class="archivenote">
      Prices shown are what the property was <strong>asking</strong> when it came off the
      market. Agentpro is not party to these transactions and does not hold the agreed
      price. Sold and let are reported by the lister.
    </p>

    <section class="cband">
      <div class="grid3">
        @forelse ($listings as $property)
          <x-property-card :property="$property" />
        @empty
          <p class="empty" style="grid-column:1/-1">
            <strong>Nothing in the archive yet</strong>
            @if ($outcome)
              Nothing has been marked {{ $outcome === 'sold' ? 'sold' : 'let' }} so far.
              <a href="{{ route('pages.closed') }}">See everything that has come off the market.</a>
            @else
              When a listing finds a buyer or a tenant it appears here, with the month it
              came off the market.
            @endif
          </p>
        @endforelse
      </div>

      {{ $listings->links() }}
    </section>

    <section class="cband archivecta">
      <h2>Still looking?</h2>
      <p>
        {{ $counts['all'] > 0 ? 'These have gone. ' : '' }}Everything currently on the market is
        verified before it goes live, and every listing shows the full cost of moving in before
        you call anyone.
      </p>
      <a href="{{ route('search') }}" class="btn btn-blue">Browse what is available</a>
    </section>
  </div>
</div>
@endsection
