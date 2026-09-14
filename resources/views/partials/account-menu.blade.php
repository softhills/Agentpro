@php
    $me = auth()->user();

    /*
     * Every console this account can actually open.
     *
     * Gated on exactly the checks the route groups use — `staff:moderator`,
     * `staff:realsure_officer`, `staff:technician` — so the menu and the router
     * cannot disagree. That mattered: the header only ever offered a link to the
     * moderation queue, so a RealSure officer and a capture technician signed in
     * to a site with no route to the one screen they exist to use. Both had to
     * be told the URL, which is not a feature, and the technician console in
     * particular had no link anywhere in the application.
     *
     * isStaff() answers true for any role when staff_role is 'admin', which is
     * why an admin sees all three: those are three consoles they can open.
     */
    $consoles = [];

    if ($me->isStaff('moderator')) {
        $consoles[] = [route('admin.queue'), 'Moderation queue', 'shield'];
        $consoles[] = [route('admin.listings'), 'All listings', 'doc'];
    }

    if ($me->isStaff('realsure_officer')) {
        $consoles[] = [route('realsure.queue'), 'RealSure verifications', 'check'];
    }

    if ($me->isStaff('technician')) {
        $consoles[] = [route('technician.assignments'), 'Your assignments', 'cube'];
    }
@endphp

{{--
    The account menu.

    A <details> element and no JavaScript for the open and close — the script in
    the layout adds outside-click and Escape, which are conveniences, not the
    mechanism. A menu that only works once a script has run is a menu that
    strands somebody on a bad connection with no way to sign out.
--}}
<details class="usermenu">
    <summary aria-label="Your account">
        <span class="avatar">{{ $me->initials() }}</span>
        <x-icon name="chevron" />
    </summary>

    <div class="usermenu-pop">
        <div class="usermenu-id">
            <strong>{{ $me->name }}</strong>
            <span>{{ $me->email }}</span>
            @if ($me->isStaff())
                <span class="usermenu-role">{{ str_replace('_', ' ', $me->staff_role ?? 'staff') }}</span>
            @endif
        </div>

        {{-- Duplicates the main bar, and only appears where the main bar does
             not: below 960px .navlinks is hidden, and without this the site has
             no navigation at all on a phone. --}}
        <div class="usermenu-g usermenu-browse">
            <p class="usermenu-h">Browse</p>
            <a href="{{ route('search', ['intent' => 'rent']) }}">Rent</a>
            <a href="{{ route('search', ['intent' => 'sale']) }}">Buy</a>
            <a href="{{ route('search', ['type' => 'land']) }}">Land</a>
            <a href="{{ route('pages.areas') }}">Areas</a>
            <a href="{{ route('pages.agents') }}">Agents</a>
            <a href="{{ route('pages.realsure') }}">RealSure</a>
            <a href="{{ route('pages.closed') }}">Sold and let</a>
        </div>

        @if ($consoles)
            <div class="usermenu-g">
                <p class="usermenu-h">Staff</p>
                @foreach ($consoles as [$href, $label, $icon])
                    <a href="{{ $href }}"><x-icon :name="$icon" />{{ $label }}</a>
                @endforeach
            </div>
        @endif

        @if ($me->canList())
            <div class="usermenu-g">
                <p class="usermenu-h">Listing</p>
                <a href="{{ route('lister.dashboard') }}"><x-icon name="home" />Your listings</a>
                <a href="{{ route('lister.listings.create') }}"><x-icon name="draw" />Add a listing</a>
                <a href="{{ route('lister.payouts') }}"><x-icon name="naira" />Getting paid</a>
                @unless ($me->isVerified())
                    <a href="{{ route('verify.show') }}"><x-icon name="check" />Verify your identity</a>
                @endunless
            </div>
        @endif

        <div class="usermenu-g">
            <p class="usermenu-h">Account</p>
            <a href="{{ route('saved-searches.index') }}"><x-icon name="search" />Saved searches</a>
            <a href="{{ route('notifications.edit') }}"><x-icon name="bell" />Notifications</a>
            {{-- NDPA ss. 34 and 38. Reachable in two clicks from every page, on
                 purpose: a right to your data that takes a support ticket to
                 exercise is not much of a right. --}}
            <a href="{{ route('privacy.index') }}"><x-icon name="shield" />Your data</a>
        </div>

        <form method="POST" action="{{ route('logout') }}" class="usermenu-out">
            @csrf
            <button type="submit">Sign out</button>
        </form>
    </div>
</details>
