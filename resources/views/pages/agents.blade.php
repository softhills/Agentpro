@extends('layouts.app')

@section('title', 'Verified agents and developers — Agentpro')
@section('meta_description', 'Every agent, owner and developer on Agentpro who has passed identity verification and has property on the market.')

@section('content')
<div class="cpage">
  <header class="chero chero-tight">
    <div class="container">
      <p class="ceyebrow">Agents</p>
      <h1>People who passed a check</h1>
      <p class="clede">
        Everyone here has verified their identity and has property on the market today. An
        account that has not been verified does not appear — listing it would be lending
        credibility nobody earned, which is the thing this platform exists to stop.
      </p>

      <form method="GET" class="agentfilter">
        <input name="q" class="finput" value="{{ $term }}" placeholder="Name or firm"
               aria-label="Search by name or firm">
        <select name="category" class="finput" aria-label="Filter by type">
          <option value="">Any type</option>
          @foreach ($categories as $value => $label)
            <option value="{{ $value }}" @selected($category === $value)>{{ $label }}</option>
          @endforeach
        </select>
        <button class="btn btn-blue">Search</button>
        @if ($term || $category)
          <a href="{{ route('pages.agents') }}" class="linkbtn">Clear</a>
        @endif
      </form>
    </div>
  </header>

  <div class="container">
    <section class="cband">
      @forelse ($agents as $agent)
        @if ($loop->first)<div class="agentgrid">@endif
          <a href="{{ route('pages.agent', $agent) }}" class="agentcard">
            <span class="avatar avatar-lg">{{ $agent->initials() }}</span>
            <span class="agentcard-body">
              <b>{{ $agent->name }}<x-verified-tick /></b>
              <span class="sub">{{ $agent->categoryLabel() }}</span>
              <span class="sub">
                {{ $agent->live_count }} {{ Str::plural('listing', $agent->live_count) }} ·
                since {{ $agent->created_at->format('M Y') }}
              </span>
            </span>
          </a>
        @if ($loop->last)</div>@endif
      @empty
        <p class="empty">
          @if ($term || $category)
            Nobody matches that. Try a shorter name, or clear the filters.
          @else
            No verified listers have property on the market yet.
          @endif
        </p>
      @endforelse

      {{ $agents->links() }}
    </section>
  </div>
</div>
@endsection
