@extends('layouts.admin')
@section('title', 'People — Agentpro admin')
@section('admin_title', 'People')
@section('admin_lede', 'Accounts needing a decision are listed first.')

@section('admin_content')
<form method="GET" class="adminfilters">
    <input type="search" name="q" value="{{ request('q') }}" class="finput" placeholder="Name or email">
    <select name="state" class="finput">
        <option value="">Any state</option>
        @foreach (['pending','verified','rejected','unverified','suspended'] as $s)
            <option value="{{ $s }}" @selected(request('state') === $s)>{{ ucfirst($s) }} ({{ $counts[$s] ?? 0 }})</option>
        @endforeach
    </select>
    <select name="category" class="finput">
        <option value="">Any category</option>
        @foreach (['seeker','independent_agent','property_owner','sellers_agent','developer','brokerage_firm'] as $c)
            <option value="{{ $c }}" @selected(request('category') === $c)>{{ ucfirst(str_replace('_',' ',$c)) }}</option>
        @endforeach
    </select>
    <button type="submit" class="btn btn-brand btn-sm">Filter</button>
</form>

<div class="tablewrap">
<table class="admintable">
    <thead><tr>
        <th>Person</th><th>Category</th><th>State</th><th>Listings</th><th>Set state</th>
    </tr></thead>
    <tbody>
    @forelse ($users as $user)
        <tr>
            <td>
                <strong>{{ $user->name }}</strong>
                <span class="sub">{{ $user->email }}</span>
                @if ($user->is_staff)<span class="tag tag-plan">{{ $user->staff_role }}</span>@endif
            </td>
            <td>{{ $user->categoryLabel() }}</td>
            <td><span class="vstate vstate-{{ $user->verification_state }}">{{ $user->verification_state }}</span></td>
            <td class="num">{{ $user->properties_count }}</td>
            <td>
                <form method="POST" action="{{ route('admin.users.verify', $user) }}" class="inlineform">
                    @csrf @method('PUT')
                    <select name="verification_state" class="finput finput-sm">
                        @foreach (['unverified','pending','verified','rejected','suspended'] as $s)
                            <option value="{{ $s }}" @selected($user->verification_state === $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-ghost btn-sm">Apply</button>
                </form>
            </td>
        </tr>
    @empty
        <tr><td colspan="5" class="fhint">Nobody matches that filter.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div style="margin-top:16px">{{ $users->links() }}</div>
@endsection
