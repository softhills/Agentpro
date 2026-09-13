@extends('layouts.app')

@section('title', 'Terms and listing policy — Agentpro')
@section('meta_description', 'The rules for using Agentpro: what we check, what we do not, what a listing must contain, and what gets a listing or an account removed.')

@section('content')
<div class="cpage">
  <header class="chero chero-tight">
    <div class="container">
      <p class="ceyebrow">Terms</p>
      <h1>The rules, in plain words</h1>
      <p class="clede">
        What the platform does, what it deliberately does not do, and what will get a listing
        taken down. Written to be read rather than clicked past.
      </p>
    </div>
  </header>

  <div class="container cprose">

    <section class="cband">
      <h2>What Agentpro is</h2>
      <p class="cbody">
        A marketplace. {{ config('agentpro.company.legal_name') }} advertises property on
        behalf of listers and provides verification services. We are not a party to any
        tenancy, sale or agreement you reach with a lister, we do not act as your agent, and
        we do not hold money for either side of a property transaction.
      </p>
      <p class="cbody">
        <b>No rent, deposit or purchase price passes through this platform.</b> The only
        payments Agentpro takes are for its own services — a 3D capture, a RealSure
        engagement — and they are taken by our payment provider, not by an individual. Anyone
        asking you to send property money "through Agentpro" is not us.
      </p>
    </section>

    <section class="cband">
      <h2>Title: what we say and what we do not</h2>
      <p class="cbody">
        Listers choose a title type from a fixed list of {{ collect($titleTypes)->flatten()->count() }}
        values and mark it available or in progress. That is a <b>declaration by the lister</b>,
        displayed as such.
      </p>
      <p class="cbody">
        Agentpro makes no representation as to the legal validity of any title unless the
        title-verification component of <a href="{{ route('pages.realsure') }}">RealSure</a>
        has been completed on that particular listing — and where it has, that is a record of
        a check we carried out on a date, not a guarantee and not legal advice.
        Instruct your own solicitor before you pay anybody anything.
      </p>
    </section>

    <section class="cband">
      <h2>If you list property</h2>
      <ol class="crules">
        <li><b>Verify your identity first.</b> You cannot submit a listing until you have, and the check is on the person or firm, not the property.</li>
        <li><b>List only property you are entitled to advertise.</b> Yours, or one you hold a mandate for.</li>
        <li><b>Itemise the cost of moving in.</b> Rent or price, agent fee, lawyer fee, caution fee and anything else required. A listing without a complete breakdown cannot be published — this is enforced, not requested.</li>
        <li><b>Use photographs of the actual property.</b> Images are fingerprinted, and the same photograph appearing on a second listing is detectable.</li>
        <li><b>Keep it current.</b> Mark it sold or rented when it goes. A listing still advertised after the property is gone is the single most common complaint in this market.</li>
        <li><b>A moderator decides.</b> Nothing publishes without review. A rejection comes back with a reason and a note you can act on, and the listing returns to draft rather than being deleted.</li>
      </ol>
      <p class="cbody sub">
        Listings run for {{ config('agentpro.display_duration_days') }} days by default. You
        will see the days remaining on your dashboard with time to renew.
      </p>
    </section>

    <section class="cband">
      <h2>What gets a listing removed</h2>
      <p class="cbody">
        Any seeker with an account can report a listing. Reports route straight to moderation
        against a target of {{ config('agentpro.sla.fraud_report_hours') }} working hours for
        anything flagged as fraudulent. The reasons a listing can be reported for are:
      </p>
      <ul class="cticks cticks-plain">
        @foreach ($reasons as $label)
          <li><x-icon name="minus" />{{ $label }}</li>
        @endforeach
      </ul>
      <p class="cbody">
        An upheld report unpublishes the listing with the reason recorded and the lister
        notified. Repeated or deliberate abuse suspends the account, which stops it publishing
        anything further.
      </p>
    </section>

    <section class="cband">
      <h2>If you are looking for property</h2>
      <p class="cbody">
        Browsing needs no account. An account is required to save a listing, rate it, report
        it, send feedback or contact a lister — partly so those actions mean something, and
        partly so we can tell you when a listing you care about changes.
      </p>
      <p class="cbody">
        Rate and report honestly. Ratings aggregate to a lister's public profile and a false
        one damages a real person's livelihood.
      </p>
    </section>

    <section class="cband">
      <h2>Your data</h2>
      <p class="cbody">
        Covered in full in the <a href="{{ route('pages.privacy') }}">privacy notice</a>,
        including what is kept if you close your account and why. You can take a copy of
        everything we hold, or close your account, from
        <a href="{{ route('privacy.index') }}">Your data</a> without emailing anybody.
      </p>
    </section>

    <section class="cband">
      <h2>Changes, and the law that applies</h2>
      <p class="cbody">
        These terms are governed by the laws of the Federal Republic of Nigeria. If we change
        anything that affects what you can do here, account holders are told before it takes
        effect rather than after.
      </p>
      <p class="cbody">
        Questions: <a href="mailto:{{ config('agentpro.company.email') }}">{{ config('agentpro.company.email') }}</a>.
      </p>
    </section>
  </div>
</div>
@endsection
