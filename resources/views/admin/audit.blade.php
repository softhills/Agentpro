@extends('layouts.admin')
@section('title', 'Audit log — Agentpro admin')
@section('admin_title', 'Audit log')
@section('admin_lede', 'Append-only. There is deliberately no way to edit or delete an entry from here.')

@section('admin_content')
<form method="GET" class="adminfilters">
    <input type="search" name="q" value="{{ request('q') }}" class="finput" placeholder="Actor name">
    <select name="action" class="finput">
        <option value="">Any action</option>
        @foreach ($actions as $a)
            <option value="{{ $a->prefix }}" @selected(request('action') === $a->prefix)>{{ $a->prefix }} ({{ $a->c }})</option>
        @endforeach
    </select>
    <button type="submit" class="btn btn-blue btn-sm">Filter</button>
</form>

<div class="tablewrap">
<table class="admintable">
    <thead><tr><th>When</th><th>Action</th><th>Subject</th><th>Actor</th><th>IP</th></tr></thead>
    <tbody>
    @forelse ($events as $e)
        <tr>
            <td class="mono">{{ \Illuminate\Support\Carbon::parse($e->created_at)->format('j M H:i') }}</td>
            <td><code>{{ $e->action }}</code></td>
            <td class="sub">{{ $e->subject_type }} #{{ $e->subject_id }}</td>
            <td>{{ $e->actor ?? 'system' }}</td>
            <td class="mono sub">{{ $e->ip ?? '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="fhint">Nothing recorded yet.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div style="margin-top:16px">{{ $events->links() }}</div>
@endsection
