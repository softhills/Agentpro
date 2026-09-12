<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'Agentpro — verified property in Lagos and Abuja')</title>
<meta name="description" content="@yield('meta_description', 'Verified property listings across Lagos and Abuja, with the full cost of moving in shown up front.')">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="{{ asset('css/app.css') }}">

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
    <a href="{{ route('home') }}" class="logo">
      <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M3 11.2 12 4l9 7.2V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-8.8Z" fill="#3E57E3"/>
        <path d="M12 4 3 11.2" stroke="#1F2A4E" stroke-width="2" stroke-linecap="round"/>
      </svg>
      Agentpro
    </a>
    <nav class="navlinks" aria-label="Main">
      <a href="{{ route('search', ['intent' => 'rent']) }}" @class(['cur' => request('intent') === 'rent'])>Rent</a>
      <a href="{{ route('search', ['intent' => 'sale']) }}" @class(['cur' => request('intent') === 'sale'])>Buy</a>
      <a href="{{ route('search', ['type' => 'land']) }}" @class(['cur' => request('type') === 'land'])>Land</a>
      <a href="#">RealSure</a>
      <a href="#">Areas</a>
      <a href="#">Agents</a>
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
        <a href="{{ route('login') }}" style="font-weight:700;font-size:14px;color:var(--navy)">Sign in</a>
        <a href="{{ route('register') }}" class="btn btn-blue btn-sm">List a property</a>
      @else
        @if (auth()->user()->isStaff('moderator'))
          <a href="{{ route('admin.queue') }}" style="font-weight:700;font-size:14px;color:var(--navy)">Queue</a>
        @endif
        @if (auth()->user()->canList())
          <a href="{{ route('lister.dashboard') }}" style="font-weight:700;font-size:14px;color:var(--navy)">Your listings</a>
        @else
          <a href="{{ route('saved-searches.index') }}" style="font-weight:700;font-size:14px;color:var(--navy)">Saved searches</a>
        @endif
        {{-- Verification state is surfaced in the chrome, not buried in the
             dashboard: a lister whose checks are pending should not have to go
             looking for the reason submission is refused. --}}
        @if (auth()->user()->canList() && ! auth()->user()->isVerified())
          <a href="{{ route('verify.show') }}" class="navflag">Verify</a>
        @endif
        <form method="POST" action="{{ route('logout') }}" style="display:inline">
          @csrf
          <button type="submit" class="navlogout">Sign out</button>
        </form>
        <span class="avatar" title="{{ auth()->user()->name }}">{{ auth()->user()->initials() }}</span>
      @endguest
    </div>
  </div>
</header>

@yield('content')

<footer class="foot">
  <div class="container in">
    <strong style="color:var(--on-navy);font-size:15px">Agentpro</strong>
    <a href="#">RealSure</a><a href="#">Areas</a><a href="#">Agents</a><a href="#">Terms</a><a href="#">Privacy</a>
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
})();
</script>

@stack('scripts')
</body>
</html>
