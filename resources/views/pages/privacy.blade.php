@extends('layouts.app')

@section('title', 'Privacy notice — Agentpro')
@section('meta_description', 'What Agentpro holds about you, why, how long for, and how to get a copy or have it erased under the Nigeria Data Protection Act 2023.')

@section('content')
@php use App\Support\PersonalData; @endphp

<div class="cpage">
  <header class="chero chero-tight">
    <div class="container">
      <p class="ceyebrow">Privacy</p>
      <h1>What we hold about you</h1>
      <p class="clede">
        Written under section 27 of the Nigeria Data Protection Act 2023. The tables below are
        generated from the same list the system itself uses when you ask to be erased, so this
        page cannot describe something different from what actually happens.
      </p>
    </div>
  </header>

  <div class="container cprose">

    <section class="cband">
      <h2>Who we are</h2>
      <p class="cbody">
        {{ config('agentpro.company.legal_name') }}
        @if (config('agentpro.company.rc_number')) (RC {{ config('agentpro.company.rc_number') }})@endif
        is the data controller for this platform.
        @if (config('agentpro.company.address')) {{ config('agentpro.company.address') }}.@endif
        Questions about anything on this page go to
        <a href="mailto:{{ $contact }}">{{ $contact }}</a>, which reaches the people who
        handle data requests.
      </p>
    </section>

    <section class="cband">
      <h2>Browsing without an account</h2>
      <p class="cbody">
        You can search and read every listing in full without telling us who you are. We do
        not require an account to look at anything.
      </p>
      <p class="cbody">
        We count how often a listing is opened so that whoever advertised it knows whether
        anyone is looking. Those counts carry nothing that identifies you — no name, no
        account, no IP address — and a total is not personal data.
        <b>We do not attach an identifier to what you do unless you have said yes to it</b>
        on the cookie banner, and if you decline we delete the one we had.
      </p>
    </section>

    <section class="cband">
      <h2>Cookies</h2>
      <dl class="kvlist">
        <div>
          <dt>Strictly necessary</dt>
          <dd>
            A session cookie that keeps you signed in and protects forms against
            cross-site request forgery, and a cookie recording your cookie choice itself.
            These cannot be switched off without breaking sign-in, and we set them on that
            basis rather than on consent.
          </dd>
        </div>
        <div>
          <dt>Analytics — optional</dt>
          <dd>
            A random identifier that lets us see a whole journey rather than isolated events:
            whether people who search a particular area go on to enquire, and how long anyone
            spends in a 3D tour. It is meaningless outside our own records, it is never sold
            or shared, and it is only set if you choose "Allow counting". Declining clears it.
          </dd>
        </div>
      </dl>
      <p class="cbody sub">
        There is no advertising tier and no third-party tracker on this site. Nothing you do
        here is shared with an advertising network.
      </p>
    </section>

    <section class="cband">
      <h2>What we hold, and what happens if you close your account</h2>
      <p class="cbody">
        Section 34 of the Act gives you the right to have your data erased. Not all of it can
        go, and we would rather say which parts here than in the small print afterwards.
      </p>

      {{-- The same three groups the account screen shows, and the same styles:
           .disposalgroup carries no margin of its own, so it needs the .disposal
           grid around it or the three boxes butt together. --}}
      <div class="disposal">
        @foreach ([
          PersonalData::DELETE => ['Deleted outright', 'Gone, with no copy kept.'],
          PersonalData::ANONYMISE => ['Kept, with your details stripped out', 'The record stays; you are no longer in it.'],
          PersonalData::RETAIN => ['Kept as it is', 'Because another law requires it, or because it is somebody else’s record too.'],
        ] as $key => [$heading, $blurb])
          <div class="disposalgroup disposal-{{ $key }}">
            <h3>{{ $heading }}</h3>
            <p class="sub">{{ $blurb }}</p>
            <ul class="plainlist">
              @foreach ($disposal[$key] as $item)
                <li><span><b>{{ $item['label'] }}</b><span class="sub">{{ $item['why'] }}</span></span></li>
              @endforeach
            </ul>
          </div>
        @endforeach
      </div>
    </section>

    <section class="cband">
      <h2>How long we keep things</h2>
      <dl class="kvlist">
        <div>
          <dt>Transaction records</dt>
          <dd>
            Six years. Nigerian revenue and anti-money-laundering rules require it, and
            section 34(2) of the Act allows for that rather than overriding another statute.
          </dd>
        </div>
        <div>
          <dt>Identity documents</dt>
          <dd>
            The document number is passed to the verification provider and then discarded. We
            keep the decision — verified or not — and the date, not the document.
          </dd>
        </div>
        <div>
          <dt>Detailed analytics</dt>
          <dd>
            {{ $retention }} days, after which only daily totals remain. Nothing that could be
            traced to one visit survives past that.
          </dd>
        </div>
        <div>
          <dt>Your account</dt>
          <dd>
            For as long as you have one. Closing it runs after {{ $graceHours }} hours — a
            pause, so that if somebody else asked for it our warning reaches you in time to
            stop it.
          </dd>
        </div>
      </dl>
    </section>

    <section class="cband">
      <h2>Your rights</h2>
      <p class="cbody">
        Under the Act you can ask for a copy of your data, have it corrected, have it erased,
        object to how it is used, and complain to the Nigeria Data Protection Commission.
      </p>
      <p class="cbody">
        <b>The first two do not need you to email anybody.</b> Sign in and open
        <a href="{{ route('privacy.index') }}">Your data</a>: a copy arrives as a single file
        you can read or move elsewhere, and closing your account is a button on the same page.
        We answer written requests within one month, as the Act requires.
      </p>
    </section>

    <section class="cband">
      <h2>Who else sees your data</h2>
      <p class="cbody">
        Only the providers needed to run the service: our payment provider, the identity
        verification provider, and the services that deliver email, SMS and WhatsApp messages
        you have asked for. No card details ever reach Agentpro systems. We do not sell
        personal data, and we do not share it with advertisers.
      </p>
      <p class="cbody sub">
        Some of these process data outside Nigeria. Where they do, that transfer is covered by
        the safeguards in Part IX of the Act.
      </p>
    </section>
  </div>
</div>
@endsection
