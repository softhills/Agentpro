@extends('layouts.app')

@section('content')
{{--
    Admin sits in its own shell: a persistent sidebar, because an operator moves
    between queue, users and orders constantly and should never have to go back
    to a hub page to do it.
--}}
<div class="adminshell">
    <aside class="adminnav" aria-label="Admin sections">
        <p class="adminnav-title">Admin</p>

        @php
            $me = auth()->user();
            $sections = [];

            /*
             * Built by role rather than filtered afterwards, because every
             * section in this list is gated by middleware that returns 404 —
             * and a sidebar link to a 404 is worse than no link. It teaches
             * whoever clicks it that the console is broken.
             *
             * A RealSure Officer is not a moderator and sees none of the
             * moderation sections; an admin satisfies every narrower check in
             * isStaff() and so sees the lot.
             */
            if ($me?->isStaff('moderator')) {
                $sections = [
                    ['admin.dashboard',  'Dashboard',  'home',   null],
                    ['admin.queue',      'Review queue', 'shield', $queueDepth ?? null],
                    ['admin.listings',   'Listings',   'doc',    null],
                    ['admin.users',      'People',     'check',  $pendingUsers ?? null],
                    ['admin.orders',     'Orders',     'phone',  $refundsWaiting ?? null],
                    ['admin.analytics',  'Funnels',    'chart',  null],
                    ['admin.operations', 'Coverage & capacity', 'pin', null],
                    ['admin.taxonomy',   'Amenities & areas', 'area', null],
                    ['admin.audit',      'Audit log',  'plan',   null],
                ];

                // Finance is admin-only, so the entry is not shown to a
                // moderator rather than being shown and then refused.
                if ($me->isStaff('admin')) {
                    array_splice($sections, 5, 0, [
                        ['admin.payouts',     'Payouts',     'naira', $payoutsWaiting ?? null],
                        ['admin.settlements', 'Settlements', 'cube',  $settlementIssues ?? null],
                    ]);
                }
            }

            // Granting a trust badge is not a moderation power, so it carries
            // its own role and its own entry (FR-M6-01).
            if ($me?->isStaff('realsure_officer')) {
                $sections[] = ['realsure.queue', 'RealSure', 'shield', $realsureOutstanding ?? null];
            }
        @endphp

        <nav>
            @foreach ($sections as [$route, $label, $icon, $badge])
                <a href="{{ route($route) }}" @class(['adminlink', 'on' => request()->routeIs($route)])>
                    <x-icon :name="$icon" />
                    <span>{{ $label }}</span>
                    @if ($badge)
                        <em class="adminbadge">{{ $badge }}</em>
                    @endif
                </a>
            @endforeach
        </nav>

        <a href="{{ route('home') }}" class="adminlink adminlink-out">
            <x-icon name="chevron" style="transform:rotate(90deg)" />
            <span>Back to the site</span>
        </a>
    </aside>

    <main class="adminmain">
        <header class="adminhead">
            <div>
                <h1>@yield('admin_title')</h1>
                @hasSection('admin_lede')
                    <p>@yield('admin_lede')</p>
                @endif
            </div>
            @yield('admin_actions')
        </header>

        <x-flash />
        <x-form-errors />

        @yield('admin_content')
    </main>
</div>
@endsection
