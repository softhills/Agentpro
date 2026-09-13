{{--
    Cookie consent (FR-M1-02, FR-M13-04).

    Rendered server-side and only when the visitor has not answered, so there is
    no flash of a banner for people who already decided, and no client-side
    check deciding whether to show it.

    Both buttons are real buttons of equal weight. A banner whose "accept" is a
    filled primary and whose "decline" is grey six-point text is a dark pattern,
    and the consent it collects is worth nothing — the default is already
    essential-only, so nothing here needs to push anybody towards yes.
--}}
@unless (\App\Support\Consent::answered())
<div class="consent" role="region" aria-label="Cookie choices">
    <div class="consent-in">
        {{--
            Shorter than it was, and deliberately not shorter still.

            "We use cookies to improve your experience" is the standard and it
            tells nobody anything, which is what makes most consent meaningless.
            The two things a person actually needs in order to choose are what
            is unavoidable and what the optional part is *for* — both are here,
            and the rest moved to the privacy page this links to.
        --}}
        <p class="consent-copy">
            <b>Cookies.</b>
            Some are needed to keep you signed in — those are always on. Separately, we can
            count how listings are found and used, so listers know how their property is doing
            and we can see where seekers are finding nothing.
            <a href="{{ route('pages.privacy') }}">How we handle your data</a>.
        </p>
        <div class="consent-acts">
            <form method="POST" action="{{ route('consent') }}">
                @csrf
                <input type="hidden" name="choice" value="{{ \App\Support\Consent::ESSENTIAL }}">
                <button class="btn btn-ghost btn-sm">Essential only</button>
            </form>
            <form method="POST" action="{{ route('consent') }}">
                @csrf
                <input type="hidden" name="choice" value="{{ \App\Support\Consent::ANALYTICS }}">
                <button class="btn btn-ghost btn-sm">Allow counting</button>
            </form>
        </div>
    </div>
</div>
@endunless
