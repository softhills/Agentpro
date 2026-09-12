@extends('layouts.app')
@section('title', 'Unsubscribed — Agentpro')
@section('content')
<div class="authwrap">
    <div class="authcard">
        <h1>Unsubscribed</h1>
        <p class="authlede">
            @if ($category === 'all')
                We have stopped sending you messages on every channel. Your account and
                saved listings are untouched.
            @else
                We have switched off {{ str_replace('_', ' ', $category) }}. Everything else
                is unchanged.
            @endif
        </p>
        <div class="statebox state-verified">
            <span class="statedot"></span>
            <div>
                <strong>Done — no sign-in needed</strong>
                <p>You can turn anything back on from your notification settings.</p>
            </div>
        </div>
        <a href="{{ route('notifications.edit') }}" class="btn btn-blue btn-block">Notification settings</a>
    </div>
</div>
@endsection
