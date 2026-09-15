@extends('layouts.app')

@section('title', 'Saved searches — Agentpro')

@section('content')
<div class="container dash">
    <div class="dashhead">
        <div>
            <h1>Saved searches</h1>
            <p>We check these for you and tell you when something new matches.</p>
        </div>
        <a href="{{ route('search') }}" class="btn btn-brand">Start a new search</a>
    </div>

    <x-flash />
    <x-form-errors />

    @forelse ($searches as $search)
        <article class="listrow savedrow">
            <div class="listmain">
                <div class="listtop">
                    <h2>{{ $search->name }}</h2>
                    @if ($search->frequency === 'off')
                        <span class="lstate">Paused</span>
                    @else
                        <span class="lstate lstate-published">{{ $search->frequencyLabel() }}</span>
                    @endif
                </div>

                <p class="listmeta">{{ $search->describe() }}</p>

                <p class="listexpiry">
                    @if ($search->new_count > 0)
                        {{ $search->new_count }} {{ Str::plural('match', $search->new_count) }} sent so far
                    @else
                        Nothing new yet
                    @endif
                    @if ($search->last_run_at)
                        · last checked {{ $search->last_run_at->diffForHumans() }}
                    @endif
                </p>
            </div>

            <div class="listacts">
                <form method="POST" action="{{ route('saved-searches.update', $search) }}">
                    @csrf @method('PUT')
                    <select name="frequency" class="minisel" onchange="this.form.submit()">
                        <option value="instant" @selected($search->frequency === 'instant')>Alert instantly</option>
                        <option value="daily" @selected($search->frequency === 'daily')>Daily digest</option>
                        <option value="off" @selected($search->frequency === 'off')>Pause</option>
                    </select>
                </form>

                <a href="{{ route('search', array_filter(array_merge($search->criteria ?? [], $search->bounds ?? []))) }}"
                   class="btn btn-ghost btn-sm">Open</a>

                <form method="POST" action="{{ route('saved-searches.destroy', $search) }}"
                      onsubmit="return confirm('Remove this saved search?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-ghost btn-sm">Remove</button>
                </form>
            </div>
        </article>
    @empty
        <div class="empty">
            <strong>No saved searches yet</strong>
            Run a search, then use “Save search” to be told when something new matches it.
        </div>
    @endforelse

    @if ($searches->isNotEmpty())
        <p class="formnote" style="margin-top:14px">
            Alerts follow your <a href="{{ route('notifications.edit') }}">notification settings</a>,
            including quiet hours.
        </p>
    @endif
</div>
@endsection
