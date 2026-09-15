@extends('layouts.app')

@section('title', 'Areas we cover — Agentpro')
@section('meta_description', 'Every area Agentpro lists in across Lagos and Abuja, with live stock counts and where 3D capture is available.')

@section('content')
<div class="cpage">
  <header class="chero chero-tight">
    <div class="container">
      <p class="ceyebrow">Areas</p>
      <h1>Where we have stock</h1>
      <p class="clede">
        Counts are live. An area with a handful of listings is one we are still building out,
        and saying so is more useful than implying we are everywhere.
      </p>
    </div>
  </header>

  <div class="container">
    @foreach ($cities as $city => $areas)
      <section class="cband">
        <h2>{{ $city }}</h2>
        <div class="arealist">
          @foreach ($areas as $area)
            <a href="{{ route('pages.area', $area) }}" class="areacard">
              <span class="areacard-name">
                {{ $area->name }}
                @if ($area->is_scan_coverage)
                  {{-- The one fact that changes what a lister can buy here. --}}
                  <em class="areatag"><x-icon name="cube" />3D capture</em>
                @endif
              </span>
              <span class="areacard-count">
                {{ $area->live_count }} <em>{{ Str::plural('listing', $area->live_count) }}</em>
              </span>
            </a>
          @endforeach
        </div>
      </section>
    @endforeach

    <section class="cband">
      <h2>Somewhere we do not cover?</h2>
      <p class="cbody">
        Saving a search tells you the moment something matching it is published, including in
        an area with nothing in it today. It is the only thing on the site that works before
        the stock exists.
      </p>
      <p class="cactions">
        <a href="{{ route('search') }}" class="btn btn-brand">Search and save one</a>
      </p>
    </section>
  </div>
</div>
@endsection
