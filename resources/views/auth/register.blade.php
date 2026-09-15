@extends('layouts.app')

@section('title', 'Create an account — Agentpro')

@section('content')
<div class="authwrap">
    <div class="authcard">
        <h1>Create your account</h1>
        <p class="authlede">Browsing needs no account. You need one to save a listing, contact an agent, or list a property yourself.</p>

        <x-form-errors />

        <form method="POST" action="{{ route('register') }}" class="stack">
            @csrf

            <x-field name="name" label="Full name or firm name" :value="old('name')" required autofocus />
            <x-field name="email" label="Email" type="email" :value="old('email')" required autocomplete="email" />
            <x-field name="phone" label="Mobile number" :value="old('phone')" placeholder="0803 000 0001" required hint="Nigerian mobile. Used for verification and enquiry alerts." />

            <div class="fieldset">
                <span class="flabel">What brings you here?</span>
                <div class="radiogrid">
                    @foreach ([
                        'seeker'            => ['Looking for a property', 'Save listings, get alerts, contact agents.'],
                        'independent_agent' => ['Independent agent', 'List and co-broke on your own account.'],
                        'property_owner'    => ['Property owner', 'List your own property, no agent.'],
                        'sellers_agent'     => ["Seller's agent", 'Mandate-backed listings and RealSure.'],
                        'developer'         => ['Developer', 'Multi-unit and off-plan inventory.'],
                        'brokerage_firm'    => ['Brokerage firm', 'Team accounts and shared inventory.'],
                    ] as $value => [$label, $blurb])
                        <label class="radio">
                            <input type="radio" name="category" value="{{ $value }}"
                                   @checked(old('category', 'seeker') === $value) required>
                            <span>
                                <strong>{{ $label }}</strong>
                                <small>{{ $blurb }}</small>
                            </span>
                        </label>
                    @endforeach
                </div>
                <p class="fhint">Listing accounts need identity verification before anything goes live.</p>
            </div>

            <x-field name="password" label="Password" type="password" required autocomplete="new-password" hint="At least 10 characters, with letters and numbers." />
            <x-field name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />

            <button type="submit" class="btn btn-brand btn-block">Create account</button>
        </form>

        @include("partials.google-button")

        <p class="authalt">Already have an account? <a href="{{ route('login') }}">Sign in</a></p>
    </div>
</div>
@endsection
