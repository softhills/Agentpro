@extends('layouts.app')

@section('title', 'Verify your identity — Agentpro')

@section('content')
<div class="authwrap">
    <div class="authcard">
        <h1>Verify your identity</h1>
        <p class="authlede">
            Every listing on Agentpro comes from a verified lister. It is the reason
            a seeker trusts what they are looking at — and the reason your listings
            get taken seriously.
        </p>

        <x-flash />
        <x-form-errors />

        {{-- FR-M1-06: every state is shown with the next action spelled out.
             A bare "pending" is indistinguishable from a broken page. --}}
        <div class="statebox state-{{ $user->verification_state }}">
            <span class="statedot"></span>
            <div>
                <strong>
                    @switch($user->verification_state)
                        @case('verified')  Verified @break
                        @case('pending')   Checks in progress @break
                        @case('rejected')  Could not be verified @break
                        @case('suspended') Account suspended @break
                        @default           Not yet verified
                    @endswitch
                </strong>
                <p>
                    @switch($user->verification_state)
                        @case('verified')
                            Verified on {{ $user->verified_at?->format('j F Y') }}. You can submit listings for review.
                            @break
                        @case('pending')
                            Submitted. Checks usually complete within a few hours — we will notify you.
                            You can build drafts in the meantime; you just cannot submit them yet.
                            @break
                        @case('rejected')
                            The details did not match. Check the name on your account matches the document exactly, then try again.
                            @break
                        @case('suspended')
                            Contact support@agentpro.ng.
                            @break
                        @default
                            Takes about two minutes. You will need your NIN, BVN, passport or driver's licence.
                    @endswitch
                </p>
            </div>
        </div>

        @if (in_array($user->verification_state, ['unverified', 'rejected'], true))
            <form method="POST" action="{{ route('verify.store') }}" class="stack">
                @csrf

                <div class="fieldset">
                    <label class="flabel" for="document_type">Document type</label>
                    <select id="document_type" name="document_type" class="finput" required>
                        <option value="nin" @selected(old('document_type') === 'nin')>National Identification Number (NIN)</option>
                        <option value="bvn" @selected(old('document_type') === 'bvn')>Bank Verification Number (BVN)</option>
                        <option value="passport" @selected(old('document_type') === 'passport')>International passport</option>
                        <option value="drivers_licence" @selected(old('document_type') === 'drivers_licence')>Driver's licence</option>
                    </select>
                </div>

                <x-field name="document_number" label="Document number" :value="old('document_number')" required
                         hint="Stored only as a reference from the verification provider — the number itself is not kept." />

                @if (in_array($user->category, ['developer', 'brokerage_firm'], true))
                    <x-field name="cac_number" label="CAC registration number" :value="old('cac_number')"
                             hint="Required for developer and brokerage accounts." />
                @endif

                @if (app()->environment('local'))
                    <p class="fhint devnote">
                        <strong>Development stub.</strong> No real check runs. A number ending
                        <code>9</code> verifies instantly, ending <code>0</code> is rejected,
                        anything else goes to pending.
                    </p>
                @endif

                <button type="submit" class="btn btn-blue btn-block">Submit for verification</button>
            </form>
        @elseif ($user->verification_state === 'verified')
            <a href="{{ route('lister.dashboard') }}" class="btn btn-blue btn-block">Go to your dashboard</a>
        @endif
    </div>
</div>
@endsection
