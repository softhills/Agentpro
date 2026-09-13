@extends('layouts.app')

@section('title', 'RealSure — verified property in Lagos and Abuja')
@section('meta_description', 'RealSure is Agentpro’s verification programme: title, search report, regulatory approvals and community checks, each dated and attributed on the listing.')

@section('content')
@php use App\Support\Money; @endphp

<div class="cpage">
  <header class="chero">
    <div class="container">
      <p class="ceyebrow">RealSure</p>
      <h1>A badge is only worth what it says it checked</h1>
      <p class="clede">
        Anyone can call a listing verified. RealSure is a list of specific checks, each one
        dated and signed by the officer who did it, printed on the listing where a seeker can
        read it before they pick up the phone.
      </p>
      <p class="cactions">
        <a href="{{ route('search', ['realsure' => 1]) }}" class="btn btn-blue">See RealSure listings</a>
        <a href="#components" class="btn btn-ghost">What gets checked</a>
      </p>
    </div>
  </header>

  <div class="container">

    {{-- What it is not. Placed first on purpose. ----------------------- --}}

    <section class="cband">
      <h2>What the badge does not mean</h2>
      <p class="cbody">
        It does not mean Agentpro is guaranteeing the property, and it is not legal advice.
        The badge records what was checked and when. Everything not on the list was not
        checked, which is why the listing shows the unchecked items too rather than quietly
        leaving them out.
      </p>
      <p class="cbody">
        <b>Title is the one to read carefully.</b> Every listing shows the title the lister
        has declared. Agentpro makes no representation as to its legal validity unless the
        title-verification component below has been completed on that particular listing —
        and even then, instruct your own solicitor before you pay anybody.
      </p>
    </section>

    {{-- The components ------------------------------------------------ --}}

    <section class="cband" id="components">
      <h2>The ten components</h2>
      <p class="cbody">
        A RealSure engagement covers some or all of these. The listing shows which ones were
        done, on what date, and by which officer.
      </p>

      <div class="cgrid">
        <div class="ccard ccard-sure">
          <h3><x-icon name="shield" />Verification</h3>
          <p class="sub">What somebody checked. These are the ones that earn the badge.</p>
          <ul class="cticks">
            @foreach ($verification as $key)
              <li><x-icon name="check" stroke-width="2.5" />{{ $components[$key] }}</li>
            @endforeach
          </ul>
        </div>

        <div class="ccard">
          <h3><x-icon name="photo" />Produced for the listing</h3>
          <p class="sub">
            Work Agentpro carried out at the property. Useful to a seeker, but it establishes
            nothing that was not already visible — so on its own it earns no badge.
          </p>
          <ul class="cticks">
            @foreach ($production as $key)
              <li><x-icon name="check" stroke-width="2.5" />{{ $components[$key] }}</li>
            @endforeach
          </ul>
        </div>
      </div>
    </section>

    {{-- The rule ------------------------------------------------------- --}}

    <section class="cband">
      <h2>What has to be true before the badge goes on</h2>
      <p class="cbody">
        Published in full, because a rule nobody can read is a rule nobody can hold us to.
      </p>
      <ol class="crules">
        <li>
          <b>A RealSure Officer grants it.</b>
          Never the lister, never automatically, and never as a side effect of paying.
        </li>
        <li>
          <b>At least {{ $minimum }} verification {{ Str::plural('check', $minimum) }} must be complete.</b>
          Photography and floor plans do not count towards this.
        </li>
        @if ($titleRequired)
          <li>
            <b>Title verification must be one of them.</b>
            It is the check the disclaimer on every listing refers to, so the badge cannot go
            on without it.
          </li>
        @endif
        <li>
          <b>It comes off if a check is withdrawn.</b>
          If something a badge rested on turns out to be wrong, the badge is removed and the
          lister is told why.
        </li>
      </ol>
    </section>

    {{-- Getting it ------------------------------------------------------ --}}

    <section class="cband">
      <h2>For listers</h2>
      <p class="cbody">
        RealSure is arranged directly rather than bought from a form — the work involves
        searches, site visits and people, and what a property needs depends on what it is.
        A full engagement is currently {{ Money::naira($price) }}.
      </p>
      <p class="cactions">
        <a href="mailto:{{ config('agentpro.company.email') }}?subject=RealSure enquiry" class="btn btn-blue">
          Talk to us about RealSure
        </a>
        @guest
          <a href="{{ route('register') }}" class="btn btn-ghost">Create a lister account</a>
        @endguest
      </p>
      @if ($verifiedCount > 0)
        <p class="cbody sub">
          {{ $verifiedCount }} live {{ Str::plural('listing', $verifiedCount) }} currently
          {{ $verifiedCount === 1 ? 'carries' : 'carry' }} the badge.
        </p>
      @endif
    </section>
  </div>
</div>
@endsection
