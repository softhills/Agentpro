<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{{-- Read by the push subscription script, which posts JSON rather than a form. --}}
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Agentpro — verified property in Lagos and Abuja')</title>
<meta name="description" content="@yield('meta_description', 'Verified property listings across Lagos and Abuja, with the full cost of moving in shown up front.')">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="{{ \App\Support\Asset::url('css/app.css') }}">

{{-- Applied before the stylesheet paints. Deferring this to the end of the body
     means a dark-theme user gets a white flash on every navigation. --}}
<script>
(function () {
    try {
        var saved = localStorage.getItem('agentpro-theme');
        var dark = saved ? saved === 'dark'
            : window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (dark) document.documentElement.setAttribute('data-theme', 'dark');
    } catch (e) { /* private mode: fall through to the light default */ }
})();
</script>
@stack('head')
</head>
<body>

{{-- Placeholder artwork. Stands in until real property media is uploaded; the
     palette is derived from the property id so a listing looks the same on
     every page rather than flickering between renders. --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="ph" viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice">
    <rect width="400" height="300" fill="var(--p1,#CBD7E6)"/>
    <circle cx="330" cy="58" r="26" fill="var(--p4,#F2E3C2)" opacity=".65"/>
    <rect x="28" y="118" width="104" height="182" fill="var(--p2,#A9BDD4)"/>
    <rect x="140" y="82" width="132" height="218" fill="var(--p3,#8FA6C2)"/>
    <rect x="280" y="140" width="92" height="160" fill="var(--p2,#A9BDD4)"/>
    <g fill="var(--p1,#CBD7E6)" opacity=".55">
      <rect x="44" y="136" width="24" height="30"/><rect x="80" y="136" width="24" height="30"/>
      <rect x="44" y="182" width="24" height="30"/><rect x="80" y="182" width="24" height="30"/>
      <rect x="44" y="228" width="24" height="30"/><rect x="80" y="228" width="24" height="30"/>
      <rect x="158" y="102" width="30" height="34"/><rect x="200" y="102" width="30" height="34"/>
      <rect x="158" y="152" width="30" height="34"/><rect x="200" y="152" width="30" height="34"/>
      <rect x="158" y="202" width="30" height="34"/><rect x="200" y="202" width="30" height="34"/>
      <rect x="296" y="160" width="26" height="30"/><rect x="332" y="160" width="26" height="30"/>
      <rect x="296" y="206" width="26" height="30"/><rect x="332" y="206" width="26" height="30"/>
    </g>
    <rect y="274" width="400" height="26" fill="var(--p5,#7E93AE)"/>
  </symbol>
</svg>

<header class="nav">
  <div class="container" style="display:flex;align-items:center;gap:28px;width:100%">
    {{--
        The wordmark, as text rather than an image.

        The brand mark is "Agentpro" plus the degree ring — no house icon, which
        is what stood here. Set in live text so it inherits the theme colour
        (the supplied artwork is white, which would disappear on this header),
        stays sharp at every size and on every screen, costs nothing to
        download, and leaves the company name as real text for search engines
        and screen readers rather than something only sighted users can read.

        The ring is drawn in CSS rather than typed as "°", because the degree
        character's size and vertical position are decided by whichever font
        loads — including the fallback — and a logo that moves when a webfont
        fails is not a logo.
    --}}
    <a href="{{ route('home') }}" class="logo">
      Agentpro<span class="logo-ring" aria-hidden="true"></span>
    </a>
    <nav class="navlinks" aria-label="Main">
      <a href="{{ route('search', ['intent' => 'rent']) }}" @class(['cur' => request('intent') === 'rent'])>Rent</a>
      <a href="{{ route('search', ['intent' => 'sale']) }}" @class(['cur' => request('intent') === 'sale'])>Buy</a>
      <a href="{{ route('search', ['type' => 'land']) }}" @class(['cur' => request('type') === 'land'])>Land</a>
      <a href="{{ route('pages.realsure') }}" @class(['cur' => request()->routeIs('pages.realsure')])>RealSure</a>
      <a href="{{ route('pages.areas') }}" @class(['cur' => request()->routeIs('pages.area*')])>Areas</a>
      <a href="{{ route('pages.agents') }}" @class(['cur' => request()->routeIs('pages.agent*')])>Agents</a>
    </nav>
    <div class="navright">
      <button type="button" class="themetoggle" data-theme-toggle
              aria-label="Switch between light and dark">
        <svg class="t-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>
        </svg>
        <svg class="t-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>
        </svg>
      </button>
      @guest
        <a href="{{ route('login') }}" class="navsignin">Sign in</a>
        <a href="{{ route('register') }}" class="btn btn-brand btn-sm">List a property</a>

        {{-- Below 960px .navlinks is hidden and a guest had no way to reach
             anything but the home page. Same disclosure pattern as the account
             menu, and it exists only at those widths. --}}
        <details class="usermenu usermenu-guest">
          <summary aria-label="Menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path d="M4 7h16M4 12h16M4 17h16" stroke-linecap="round"/>
            </svg>
          </summary>
          <div class="usermenu-pop">
            <div class="usermenu-g">
              <p class="usermenu-h">Browse</p>
              <a href="{{ route('search', ['intent' => 'rent']) }}">Rent</a>
              <a href="{{ route('search', ['intent' => 'sale']) }}">Buy</a>
              <a href="{{ route('search', ['type' => 'land']) }}">Land</a>
              <a href="{{ route('pages.areas') }}">Areas</a>
              <a href="{{ route('pages.agents') }}">Agents</a>
              <a href="{{ route('pages.realsure') }}">RealSure</a>
              <a href="{{ route('pages.closed') }}">Sold and let</a>
            </div>
            <div class="usermenu-g">
              <p class="usermenu-h">Account</p>
              <a href="{{ route('login') }}">Sign in</a>
              <a href="{{ route('register') }}">Create an account</a>
            </div>
          </div>
        </details>
      @else
        {{-- One link in the bar, for whatever this account came here to do.
             Everything else is in the menu — see partials/account-menu. --}}
        @php
          $me = auth()->user();
          $primary = match (true) {
              $me->isStaff('moderator')         => [route('admin.queue'), 'Queue'],
              $me->isStaff('realsure_officer')  => [route('realsure.queue'), 'Verifications'],
              $me->isStaff('technician')        => [route('technician.assignments'), 'Assignments'],
              $me->canList()                    => [route('lister.dashboard'), 'Your listings'],
              default                           => [route('saved-searches.index'), 'Saved searches'],
          };
        @endphp
        <a href="{{ $primary[0] }}" class="navprimary">{{ $primary[1] }}</a>

        {{-- Verification state is surfaced in the chrome, not buried in the
             dashboard: a lister whose checks are pending should not have to go
             looking for the reason submission is refused. --}}
        @if ($me->canList() && ! $me->isVerified())
          <a href="{{ route('verify.show') }}" class="navflag">Verify</a>
        @endif

        @include('partials.account-menu')
      @endguest
    </div>
  </div>
</header>

@yield('content')

@include('partials.consent')

<footer class="foot">
  <div class="container in">
    {{-- The same mark, not a second one typed out by hand. --}}
    <span class="logo">Agentpro<span class="logo-ring" aria-hidden="true"></span></span>
    <a href="{{ route('pages.realsure') }}">RealSure</a><a href="{{ route('pages.areas') }}">Areas</a><a
       href="{{ route('pages.agents') }}">Agents</a><a href="{{ route('pages.closed') }}">Sold and let</a><a
       href="{{ route('pages.about') }}">About</a><a
       href="{{ route('pages.terms') }}">Terms</a><a href="{{ route('pages.privacy') }}">Privacy</a>
    <p class="legal">
      Title information shown on listings is declared by the lister. Agentpro makes no
      representation as to the legal validity of any title unless the title-verification
      component of RealSure has been completed on that listing.
    </p>
  </div>
</footer>

<script>
(function () {
    var root = document.documentElement;

    document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dark = root.getAttribute('data-theme') === 'dark';
            root.setAttribute('data-theme', dark ? 'light' : 'dark');
            try { localStorage.setItem('agentpro-theme', dark ? 'light' : 'dark'); } catch (e) {}
        });
    });

    /*
     * Conveniences for the header menus, not the mechanism.
     *
     * <details> opens and closes them on its own, so this file failing to load
     * leaves a menu that still works — it just stays open until you click the
     * summary again. Everything below is what a browser does not give you for
     * free: close when the click lands elsewhere, close on Escape, and never
     * leave two of them open at once.
     */
    document.addEventListener('click', function (event) {
        document.querySelectorAll('details.usermenu[open]').forEach(function (menu) {
            if (! menu.contains(event.target)) { menu.open = false; }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') { return; }

        document.querySelectorAll('details.usermenu[open]').forEach(function (menu) {
            menu.open = false;
            // Focus goes back to the control that opened it, or the next tab
            // lands at the top of the document.
            menu.querySelector('summary').focus();
        });
    });
})();
</script>

@stack('scripts')
</body>
</html>
