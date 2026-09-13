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
        <p class="consent-copy">
            <strong>Cookies.</strong>
            We need a few to keep you signed in and to keep forms secure — those are always on.
            Separately, we can count how listings are found and used, to tell listers how their
            property is doing and to see where seekers are searching and finding nothing.
            <a href="{{ route('privacy.index') }}">What we hold about you</a>.
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
