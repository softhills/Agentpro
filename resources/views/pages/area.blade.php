@extends('layouts.app')

@section('title', 'Property in '.$area->name.', '.$area->city.' — Agentpro')
@section('meta_description', 'Verified property to rent and buy in '.$area->name.', '.$area->city.', with the full cost of moving in shown before you call anyone.')

@section('content')
@php use App\Support\Money; @endphp

<div class="cpage">
  <header class="chero chero-tight">
    <div class="container">
      <p class="ceyebrow"><a href="{{ route('pages.areas') }}">Areas</a> · {{ $area->city }}</p>
      <h1>{{ $area->name }}</h1>

      @if ($count === 0)
        <p class="clede">
          Nothing live here at the moment. Save a search and you will hear the day that
          changes — it is the only thing on the site that works before the stock does.
        </p>
        <p class="cactions">
          <a href="{{ route('search', ['area' => $area->slug]) }}" class="btn btn-blue">Search and save one</a>
        </p>
      @else
        {{--
            Facts, not neighbourhood prose. Whether an area is "vibrant" is
            Release 2's job (M8) and writing a placeholder version of it now
            would mean publishing something nobody checked about somewhere
            people actually live.
        --}}
        <dl class="areastats">
          <div><dt>Live listings</dt><dd>{{ $count }}</dd></div>
          @if ($median)
            {{-- The median: one penthouse would drag an average somewhere no
                 seeker could use it. --}}
            <div><dt>Typical price</dt><dd>{{ Money::naira($median) }}</dd></div>
          @endif
          @if ($cheapest)
            <div><dt>From</dt><dd>{{ Money::naira($cheapest) }}</dd></div>
          @endif
          <div>
            <dt>RealSure verified</dt>
            <dd>{{ $verified }}<span>of {{ $count }}</span></dd>
          </div>
        </dl>

        <p class="cactions">
          <a href="{{ route('search', ['area' => $area->slug]) }}" class="btn btn-blue">
            Search {{ $area->name }}
          </a>
          @if ($area->is_scan_coverage)
            <span class="areatag areatag-lg"><x-icon name="cube" />3D capture available here</span>
          @endif
        </p>
      @endif
    </div>
  </header>

  @if ($live->isNotEmpty())
    <div class="container">
      <section class="cband">
        <h2>On the market now</h2>
        <div class="grid3">
          @foreach ($live as $property)
            <x-property-card :property="$property" />
          @endforeach
        </div>
        @if ($count > $live->count())
          <p class="cactions">
            <a href="{{ route('search', ['area' => $area->slug]) }}" class="btn btn-ghost">
              See all {{ $count }} in {{ $area->name }}
            </a>
          </p>
        @endif
      </section>
    </div>
  @endif
</div>
@endsection
