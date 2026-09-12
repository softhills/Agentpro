@extends('layouts.app')
@section('title', 'Notification settings — Agentpro')
@section('content')
<div class="container formwrap" style="max-width:640px">
    <h1>Notifications</h1>
    <x-flash />
    <x-form-errors />

    <form method="POST" action="{{ route('notifications.update') }}" class="stack">
        @csrf @method('PUT')

        <section class="formsec">
            <h2>How we reach you</h2>
            <p class="secblurb">
                WhatsApp and SMS are off by default — both cost money to send and land
                somewhere more intrusive than an inbox.
            </p>
            @foreach ([
                'email' => ['Email', 'Receipts, decisions and listing updates.'],
                'push' => ['Push', 'Browser alerts while you are signed in.'],
                'whatsapp' => ['WhatsApp', 'Time-sensitive things only, using approved templates.'],
                'sms' => ['SMS', 'Fallback when nothing else reaches you.'],
            ] as $key => [$label, $blurb])
                <label class="prefrow">
                    <input type="hidden" name="{{ $key }}" value="0">
                    <input type="checkbox" name="{{ $key }}" value="1" @checked($preferences->enabled($key))>
                    <span><strong>{{ $label }}</strong><small>{{ $blurb }}</small></span>
                </label>
            @endforeach
        </section>

        <section class="formsec">
            <h2>Quiet hours</h2>
            <p class="secblurb">
                Interruptive channels are held back during these hours. Email and your
                inbox still arrive, because they wait to be read. Anything genuinely
                time-critical — a technician arriving, a payment receipt — always comes through.
            </p>
            <label class="prefrow">
                <input type="hidden" name="quiet_enabled" value="0">
                <input type="checkbox" name="quiet_enabled" value="1" @checked($preferences->get('quiet_enabled'))>
                <span><strong>Observe quiet hours</strong></span>
            </label>
            <div class="row3">
                <div class="fieldset">
                    <label class="flabel" for="quiet_from">From</label>
                    <input type="time" id="quiet_from" name="quiet_from" class="finput" value="{{ $preferences->get('quiet_from') }}">
                </div>
                <div class="fieldset">
                    <label class="flabel" for="quiet_to">Until</label>
                    <input type="time" id="quiet_to" name="quiet_to" class="finput" value="{{ $preferences->get('quiet_to') }}">
                </div>
            </div>
        </section>

        <section class="formsec">
            <h2>What you hear about</h2>
            <label class="prefrow">
                <input type="hidden" name="listing_updates" value="0">
                <input type="checkbox" name="listing_updates" value="1" @checked($preferences->wants('listing_updates'))>
                <span><strong>Listings I saved</strong><small>Price changes, availability, a new 3D tour.</small></span>
            </label>
            <label class="prefrow">
                <input type="hidden" name="saved_searches" value="0">
                <input type="checkbox" name="saved_searches" value="1" @checked($preferences->wants('saved_searches'))>
                <span><strong>Saved searches</strong><small>New listings matching a search you saved.</small></span>
            </label>
        </section>

        <button type="submit" class="btn btn-blue">Save settings</button>
    </form>
</div>
@endsection
