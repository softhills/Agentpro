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
            $sections = [
                ['admin.dashboard',  'Dashboard',  'home',   null],
                ['admin.queue',      'Review queue', 'shield', $queueDepth ?? null],
                ['admin.listings',   'Listings',   'doc',    null],
                ['admin.users',      'People',     'check',  $pendingUsers ?? null],
                ['admin.orders',     'Orders',     'phone',  $refundsWaiting ?? null],
                ['admin.operations', 'Coverage & capacity', 'pin', null],
                ['admin.taxonomy',   'Amenities & areas', 'area', null],
                ['admin.audit',      'Audit log',  'plan',   null],
            ];

            // Finance is admin-only, so the entry is not shown to a moderator
            // rather than being shown and then refused.
            if (auth()->user()?->isStaff('admin')) {
                array_splice($sections, 5, 0, [
                    ['admin.settlements', 'Settlements', 'naira', $settlementIssues ?? null],
                ]);
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
