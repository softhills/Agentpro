@extends('layouts.app')

@section('title', $agent->name.' — '.$agent->categoryLabel().' on Agentpro')
@section('meta_description', $agent->name.' is a verified '.Str::lower($agent->categoryLabel()).' on Agentpro with '.$listings->total().' '.Str::plural('listing', $listings->total()).' on the market.')

@section('content')
<div class="cpage">
  <header class="chero chero-tight">
    <div class="container">
      <p class="ceyebrow"><a href="{{ route('pages.agents') }}">Agents</a></p>

      <div class="profilehead">
        <span class="avatar avatar-xl">{{ $agent->initials() }}</span>
        <div>
          <h1>{{ $agent->name }}<x-verified-tick /></h1>
          <p class="clede">
            {{ $agent->categoryLabel() }}@if ($agent->organisation) at {{ $agent->organisation->name }}@endif ·
            on Agentpro since {{ $agent->created_at->format('F Y') }}
          </p>
        </div>
      </div>

      {{-- FR-M1-07: the six facts the requirement asks for, and nothing the
           lister wrote about themselves. Every figure here is derived. --}}
      <dl class="areastats">
        <div><dt>On the market</dt><dd>{{ $listings->total() }}</dd></div>
        <div><dt>RealSure verified</dt><dd>{{ $verifiedListings }}<span>of {{ $listings->total() }}</span></dd></div>
        <div>
          <dt>Identity</dt>
          <dd class="ddsure">Verified<span>{{ $agent->verified_at?->format('M Y') }}</span></dd>
        </div>
        <div>
          <dt>Rating</dt>
          @if ($rating)
            <dd>{{ number_format($rating['average'], 1) }}<span>from {{ $rating['total'] }} {{ Str::plural('rating', $rating['total']) }}</span></dd>
          @else
            {{--
                A "5.0" from one rating is not a reputation, and printing it as
                one would mislead in the lister's favour — the opposite of what
                this platform is for. Below the threshold the profile says how
                many there are and stops.
            --}}
            <dd class="ddnone">—<span>{{ $ratingsSoFar }} so far, {{ $minimumRatings }} needed</span></dd>
          @endif
        </div>
      </dl>
    </div>
  </header>

  <div class="container">
    <section class="cband">
      <h2>{{ $listings->total() }} on the market</h2>

      @if ($listings->isEmpty())
        <p class="empty">Nothing live right now.</p>
      @else
        <div class="grid3">
          @foreach ($listings as $property)
            <x-property-card :property="$property" />
          @endforeach
        </div>
        {{ $listings->links() }}
      @endif
    </section>

    <section class="cband">
      <h2>What this page is</h2>
      <p class="cbody">
        Everything above is derived from what has happened on the platform — listings
        published, checks completed, ratings left by seekers who used the site. None of it is
        written by the lister, and there is nothing here they can edit.
      </p>
      <p class="cbody sub">
        Verification confirms who this person is. It is not a recommendation, and it says
        nothing about any individual property — that is what the
        <a href="{{ route('pages.realsure') }}">RealSure badge</a> on a listing is for.
      </p>
    </section>
  </div>
</div>
@endsection
