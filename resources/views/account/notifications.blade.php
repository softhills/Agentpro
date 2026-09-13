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
                'push' => ['Push', 'Alerts from your browser, even when Agentpro is closed.'],
                'whatsapp' => ['WhatsApp', 'Time-sensitive things only, using approved templates.'],
                'sms' => ['SMS', 'A text about your own listings and account — never search results.'],
            ] as $key => [$label, $blurb])
                <label class="prefrow">
                    <input type="hidden" name="{{ $key }}" value="0">
                    <input type="checkbox" name="{{ $key }}" value="1" @checked($preferences->enabled($key))>
                    <span><strong>{{ $label }}</strong><small>{{ $blurb }}</small></span>
                </label>
            @endforeach

            {{--
                A switch that cannot work is worse than no switch. SMS needs a
                number we can actually reach, so the gap is stated here rather
                than discovered when a message never arrives.
            --}}
            @if ($preferences->enabled('sms') && ! $smsNumber)
                <p class="prefnote prefnote-warn">
                    SMS is on, but there is no usable Nigerian mobile number on your account,
                    so nothing can be sent. Add one on
                    <a href="{{ route('verify.show') }}">your account details</a>.
                </p>
            @elseif ($smsNumber)
                <p class="prefnote">Texts go to {{ $smsNumber }}.</p>
            @endif
        </section>

        {{--
            Push is per browser, not per account, so it cannot be a checkbox on
            a form that was submitted from somewhere else. Someone who turned it
            on at the office and is now on their phone needs to see both facts.
        --}}
        <section class="formsec" data-push>
            <h2>This device</h2>
            <p class="secblurb">
                The switch above is for your whole account. Push also has to be allowed
                by each browser you use, one at a time.
            </p>

            <div class="pushdevice">
                <button type="button" class="btn btn-ghost btn-sm" data-push-toggle="on">Checking…</button>
                <span class="pushstatus" data-push-status>Checking this browser…</span>
            </div>

            @if (! $preferences->enabled('push'))
                <p class="prefnote prefnote-warn">
                    Push is switched off for your account above, so this device will not
                    receive anything until you turn it back on.
                </p>
            @endif
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

    @if ($devices->isNotEmpty())
        <section class="formsec">
            <h2>Devices receiving push</h2>
            <p class="secblurb">
                Anything listed here can receive your notifications. Remove one you no longer
                use — an old phone is still listening until you do.
            </p>
            <ul class="devicelist">
                @foreach ($devices as $device)
                    <li>
                        <span>
                            <strong>{{ $device->deviceLabel() }}</strong>
                            <small>
                                added {{ $device->created_at->diffForHumans() }}
                                @if ($device->last_used_at)
                                    · last used {{ $device->last_used_at->diffForHumans() }}
                                @endif
                            </small>
                        </span>
                        <form method="POST" action="{{ route('push.forget', $device) }}">
                            @csrf @method('DELETE')
                            <button class="linkbtn">Remove</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{--
        The only route to the privacy screen in the chrome. Settings is where
        people look for it, and a statutory right nobody can find is not one
        they have in practice.
    --}}
    <p class="formnote" style="margin-top:16px">
        Want a copy of everything we hold about you, or want it erased?
        <a href="{{ route('privacy.index') }}">Your data</a>.
    </p>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/push.js') }}" defer></script>
@endpush
