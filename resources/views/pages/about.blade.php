@extends('layouts.app')

@section('title', 'About Agentpro')
@section('meta_description', 'Why Agentpro exists: in Lagos and Abuja the scarce thing is not property listings but trustworthy ones.')

@section('content')
<div class="cpage">
  <header class="chero chero-tight">
    <div class="container">
      <p class="ceyebrow">About</p>
      <h1>The scarce thing is not listings</h1>
      <p class="clede">
        There is no shortage of property advertised in Lagos and Abuja. There is a shortage of
        listings a person can believe — ones that are real, still available, and priced at
        what it will actually cost to move in.
      </p>
    </div>
  </header>

  <div class="container">
    <section class="cband">
      <h2>What we do differently</h2>
      <div class="cgrid">
        <div class="ccard">
          <h3><x-icon name="check" />Nobody publishes on their own</h3>
          <p class="sub">
            Every lister verifies their identity before they can submit, and every listing is
            reviewed by a person before it goes live. A rejection comes back with a reason
            you can act on rather than silence.
          </p>
        </div>
        <div class="ccard">
          <h3><x-icon name="naira" />The price is the price</h3>
          <p class="sub">
            A listing cannot be published without an itemised breakdown — rent, agent fee,
            lawyer fee, caution fee — and the total you would pay on move-in, with the
            refundable part separated out. Fees surfacing at signing is the problem; this is
            the answer to it.
          </p>
        </div>
        <div class="ccard">
          <h3><x-icon name="cube" />See it without going</h3>
          <p class="sub">
            3D tours, walkthrough video, 360° imagery and Street View, none of which load
            until you tap them — so reading a full listing on mobile data costs you almost
            nothing.
          </p>
        </div>
        <div class="ccard ccard-sure">
          <h3><x-icon name="shield" />Checks you can read</h3>
          <p class="sub">
            <a href="{{ route('pages.realsure') }}">RealSure</a> lists exactly which checks
            were done on a property, on what date, by which officer — and which were not.
          </p>
        </div>
      </div>
    </section>

    <section class="cband">
      <h2>Where we are today</h2>
      <p class="cbody">
        Live figures rather than a target. We would rather be small and honest about it.
      </p>
      <dl class="areastats">
        <div><dt>Listings on the market</dt><dd>{{ number_format($live) }}</dd></div>
        <div><dt>Verified listers</dt><dd>{{ number_format($listers) }}</dd></div>
        <div><dt>Areas with 3D capture</dt><dd>{{ $areas }}</dd></div>
      </dl>
    </section>

    <section class="cband">
      <h2>What Agentpro is not</h2>
      <p class="cbody">
        We do not hold your rent, deposit or purchase money — no payment for a property
        passes through this platform, and anybody telling you otherwise is not us. We do not
        arrange mortgages or financing; where a listing is tagged for either, that is the
        lister saying it may be possible, not an offer. And we make no representation about
        the legal validity of any title unless the title-verification component of RealSure
        has been completed on that listing.
      </p>
      <p class="cbody">
        Instruct your own solicitor. Every time.
      </p>
    </section>

    <section class="cband">
      <h2>Talk to us</h2>
      <p class="cbody">
        <a href="mailto:{{ config('agentpro.company.email') }}">{{ config('agentpro.company.email') }}</a>
        @if (config('agentpro.company.phone'))
          · {{ config('agentpro.company.phone') }}
        @endif
      </p>
      <p class="cbody sub">
        Reporting a listing is faster from the listing itself — the report goes straight to
        moderation with a target of {{ config('agentpro.sla.fraud_report_hours') }} working hours
        on anything flagged as fraudulent.
      </p>
    </section>
  </div>
</div>
@endsection
