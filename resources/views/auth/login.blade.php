@extends('layouts.app')

@section('title', 'Sign in — Agentpro')

@section('content')
<div class="authwrap">
    <div class="authcard">
        <h1>Sign in</h1>
        <p class="authlede">Welcome back.</p>

        <x-flash />
        <x-form-errors />

        <form method="POST" action="{{ route('login') }}" class="stack">
            @csrf

            <x-field name="email" label="Email" type="email" :value="old('email')" required autofocus autocomplete="email" />
            <x-field name="password" label="Password" type="password" required autocomplete="current-password" />

            <label class="checkline">
                <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                <span>Keep me signed in</span>
            </label>

            <button type="submit" class="btn btn-blue btn-block">Sign in</button>
        </form>

        <p class="authalt">No account yet? <a href="{{ route('register') }}">Create one</a></p>
    </div>
</div>
@endsection
