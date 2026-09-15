@extends('layouts.app')

@section('title', 'Your data — Agentpro')

@section('content')
@php
    use App\Support\PersonalData;
@endphp

<div class="container formwrap" style="max-width:760px">
    <h1>Your data</h1>
    <x-flash />
    <x-form-errors />

    <p class="secblurb">
        The Nigeria Data Protection Act gives you two rights over what we hold about you:
        to be given a copy of it, and to have it erased. Both are below, and neither needs
        you to ask us by email first. If you would rather write to a person,
        <a href="mailto:{{ config('agentpro.privacy.contact') }}">{{ config('agentpro.privacy.contact') }}</a>
        reaches the people who handle these.
    </p>

    {{-- Copy ---------------------------------------------------------------- --}}

    <section class="formsec">
        <h2>Get a copy of your data</h2>
        <p class="secblurb">
            A single JSON file — readable by you, and by anything you might want to move it
            into. It covers every section listed further down, including the parts we keep
            for legal reasons.
        </p>

        @if ($openExport?->isDownloadable())
            <div class="bankcard">
                <div>
                    <strong>Your file is ready</strong>
                    {{--
                        An exact time, not "2 days from now". This file is
                        deleted on a deadline, and a relative phrase is the
                        wrong unit for something somebody has to act before.
                    --}}
                    <span class="sub">
                        {{ number_format($openExport->file_bytes / 1024, 1) }} KB ·
                        available until {{ $openExport->expires_at->format('D j M, g:ia') }}
                    </span>
                </div>
                <a href="{{ route('privacy.download', $openExport) }}" class="btn btn-brand btn-sm">Download</a>
            </div>
        @elseif ($openExport)
            {{--
                The standing status, not the acknowledgement — the flash message
                right after the request already said "we are putting it
                together". Saying the same sentence twice on one screen reads as
                a bug, and this one has a different job: it is what somebody
                sees when they come back an hour later.
            --}}
            <p class="prefnote">
                Still being prepared, asked for {{ $openExport->created_at->diffForHumans() }}.
                You will get a message the moment it is ready.
            </p>
        @else
            <details class="addbox">
                <summary class="btn btn-ghost btn-sm">Request a copy</summary>
                <form method="POST" action="{{ route('privacy.export') }}" class="stack">
                    @csrf
                    {{--
                        The password is not ceremony. This file is everything we hold
                        about you in one place, so an unlocked laptop should not be
                        enough to walk off with it.
                    --}}
                    <div class="fieldset">
                        <label class="flabel" for="export_password">Your password</label>
                        <input id="export_password" name="password" type="password" class="finput"
                               autocomplete="current-password" required>
                        <span class="fhint">
                            Asked for because this file is everything we hold about you in one place.
                        </span>
                    </div>
                    <p class="secblurb">
                        We will message you when it is ready rather than handing it over here, and the
                        download stays behind your password — we do not put a copy of your whole
                        account behind a link in an email. It is deleted after
                        {{ $expiryHours }} hours; asking again is free.
                    </p>
                    <button class="btn btn-brand">Request a copy</button>
                </form>
            </details>
        @endif
    </section>

    {{-- What happens on erasure --------------------------------------------- --}}

    <section class="formsec">
        <h2>What closing your account does</h2>
        <p class="secblurb">
            Not everything can go, and we would rather say so here than in the small print
            afterwards. The Act allows us to keep records we are required by other laws to
            keep — which in practice means money.
        </p>

        <div class="disposal">
            @foreach ([
                PersonalData::DELETE    => ['Deleted outright', 'Gone, with no copy kept.'],
                PersonalData::ANONYMISE => ['Kept, with your details stripped out', 'The record stays; you are no longer in it.'],
                PersonalData::RETAIN    => ['Kept as it is', 'Because another law requires it, or because it is somebody else’s record too.'],
            ] as $key => [$heading, $blurb])
                <div class="disposalgroup disposal-{{ $key }}">
                    <h3>{{ $heading }}</h3>
                    <p class="sub">{{ $blurb }}</p>
                    <ul class="plainlist">
                        @foreach ($disposal[$key] as $item)
                            <li>
                                <span>
                                    <b>{{ $item['label'] }}</b>
                                    <span class="sub">{{ $item['why'] }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Erasure -------------------------------------------------------------- --}}

    <section class="formsec">
        <h2>Close your account</h2>

        @if ($openErasure)
            <div class="prefnote prefnote-warn">
                <strong>This account is scheduled to be closed
                    {{ $openErasure->executes_at->diffForHumans() }}</strong>
                — on {{ $openErasure->executes_at->format('l j F, g:ia') }}.
                Nothing has been deleted yet, and you can stop it right up to that moment.
                @if ($openErasure->ip)
                    <br>The request came from {{ $openErasure->ip }}@if ($openErasure->user_agent), using {{ $openErasure->user_agent }}@endif.
                @endif
            </div>

            <form method="POST" action="{{ route('privacy.cancel', $openErasure) }}" class="stack">
                @csrf
                <button class="btn btn-brand">Stop this — keep my account</button>
            </form>

            <p class="prefnote">
                If you did not ask for this, somebody else is signed in as you. Stop it, then
                change your password.
            </p>

        @elseif ($blockers)
            {{--
                Refusals are listed in full rather than one at a time. Somebody
                settling one thing only to be shown the next is how a privacy
                right turns into an obstacle course.
            --}}
            <p class="secblurb">
                There {{ count($blockers) === 1 ? 'is one thing' : 'are '.count($blockers).' things' }}
                to settle first. Each one exists because closing the account now would destroy
                your side of it, not ours.
            </p>
            <ul class="plainlist">
                @foreach ($blockers as $blocker)
                    <li><span class="warnink">{{ $blocker }}</span></li>
                @endforeach
            </ul>

        @else
            <details class="addbox">
                <summary class="linkbtn danger">Close my account permanently</summary>

                <p class="prefnote prefnote-warn" style="margin-bottom:14px">
                    This cannot be undone. Your account is closed after
                    {{ $graceHours }} hours, not immediately — that pause is there so that if
                    somebody else asked for this, the message we send you arrives in time
                    for you to stop it.
                </p>

                <form method="POST" action="{{ route('privacy.erase') }}" class="stack">
                    @csrf
                    <div class="fieldset">
                        <label class="flabel" for="erase_password">Your password</label>
                        <input id="erase_password" name="password" type="password" class="finput"
                               autocomplete="current-password" required>
                    </div>
                    <div class="fieldset">
                        <label class="flabel" for="erase_confirm">Type <b>CLOSE MY ACCOUNT</b> to confirm</label>
                        <input id="erase_confirm" name="confirm" class="finput"
                               autocomplete="off" spellcheck="false" required>
                        <span class="fhint">In capitals, exactly as written.</span>
                    </div>
                    <button class="btn btn-danger">Close my account</button>
                </form>
            </details>
        @endif
    </section>

    {{-- History -------------------------------------------------------------- --}}

    @if ($requests->isNotEmpty())
        <section class="formsec">
            <h2>What you have asked for</h2>
            <p class="secblurb">
                Kept so that both of us can see what was asked and when it was answered.
            </p>
            <div class="tablewrap">
            <table class="admintable">
                <thead><tr><th>Asked for</th><th>When</th><th>Where it got to</th><th></th></tr></thead>
                <tbody>
                @foreach ($requests as $item)
                    <tr>
                        <td>{{ $item->kind === 'export' ? 'A copy of my data' : 'Close my account' }}</td>
                        <td>{{ $item->created_at->format('j M Y, g:ia') }}</td>
                        <td>
                            {{ $item->stateLabel() }}
                            @if ($item->note)
                                <span class="sub">{{ $item->note }}</span>
                            @endif
                        </td>
                        <td>
                            @if ($item->isDownloadable())
                                <a href="{{ route('privacy.download', $item) }}" class="linkbtn">Download</a>
                            @elseif ($item->isCancellable())
                                <form method="POST" action="{{ route('privacy.cancel', $item) }}" class="inlineform">
                                    @csrf
                                    <button class="linkbtn">Cancel</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </section>
    @endif

    <p class="formnote" style="margin-top:16px">
        To change how we contact you rather than what we hold,
        <a href="{{ route('notifications.edit') }}">notification settings</a>.
    </p>
</div>
@endsection
