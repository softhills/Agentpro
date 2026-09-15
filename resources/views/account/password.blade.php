@extends('layouts.app')
@php $hasPassword = auth()->user()->hasPassword(); @endphp
@section('title', ($hasPassword ? 'Change' : 'Set').' your password — Agentpro')
@section('content')
<div class="container formwrap" style="max-width:560px">
    <h1>{{ $hasPassword ? 'Change your password' : 'Set a password' }}</h1>
    <x-flash />
    <x-form-errors />

    <form method="POST" action="{{ route('password.update') }}" class="stack">
        @csrf @method('PUT')

        <section class="formsec">
            <h2>Password</h2>
            <p class="secblurb">
                {{ $hasPassword ? 'Changing' : 'Setting' }} this signs you out everywhere else — every other browser, phone and
                device. That is the point: the usual reason to change a password is that
                somebody else may have it, and a change that left them signed in would
                achieve nothing.
            </p>

            {{-- An account created through Google has no password to confirm,
                 so asking for one would demand something that does not exist
                 and lock that person out of ever setting one. Their Google
                 session is what authenticated them. --}}
            @if ($hasPassword)
                <div class="fieldset">
                    <label class="flabel" for="current_password">Your current password</label>
                    {{-- autocomplete tells a password manager which field is
                         which. Without it managers routinely fill the
                         new-password boxes with the old one, and the person
                         cannot tell why the form keeps refusing them. --}}
                    <input type="password" id="current_password" name="current_password"
                           class="finput" required autocomplete="current-password">
                    <p class="fhint">Asked for even though you are signed in — it is what stops a borrowed laptop becoming a permanent takeover.</p>
                </div>
            @else
                <p class="fhint" style="margin:0">
                    You signed up with Google, so there is no current password to confirm.
                    Setting one gives you a second way in — useful if you ever lose access
                    to that Google account. You can keep using Google either way.
                </p>
            @endif

            <div class="fieldset">
                <label class="flabel" for="password">{{ $hasPassword ? 'New password' : 'Password' }}</label>
                <input type="password" id="password" name="password"
                       class="finput" required autocomplete="new-password">
                <p class="fhint">
                    At least 10 characters, with letters and numbers. It is also checked
                    against known breached passwords — if it has appeared in a public breach,
                    it will be refused however long it is.
                </p>
            </div>

            <div class="fieldset">
                <label class="flabel" for="password_confirmation">{{ $hasPassword ? 'New password again' : 'Password again' }}</label>
                <input type="password" id="password_confirmation" name="password_confirmation"
                       class="finput" required autocomplete="new-password">
            </div>

            <div class="formactions" style="margin-bottom:0">
                <button type="submit" class="btn btn-brand">{{ $hasPassword ? 'Change password' : 'Set password' }}</button>
                <a href="{{ route('home') }}" class="btn btn-ghost">Cancel</a>
            </div>
        </section>
    </form>

    <p class="fhint" style="margin-top:14px">
        We will email you whenever this password changes — including if it was not you
        who changed it.
    </p>
</div>
@endsection
