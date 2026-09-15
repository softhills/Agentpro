@props(['property', 'unit'])

@php
    use App\Support\Money;
@endphp

{{--
    The move-in cost breakdown (M7).

    The single most valuable block on the page: it resolves an advertised rent
    into the amount a tenant must actually find, and separates what comes back
    at the end of the tenancy from what does not.

    Set in Inter rather than Quicksand — see Q16. A column of naira figures needs
    the vertical rhythm that Quicksand's rounded terminals give up at this size.
--}}
<div class="pricecard">
    <div class="top">
        <p class="big">
            {{ Money::naira($unit?->price) }}
            @if ($unit && $unit->price_period->suffix())
                <span class="per">{{ $unit->price_period->suffix() }}</span>
            @endif
        </p>
        <p class="sub">
            @if ($unit?->available_from)
                Available from {{ $unit->available_from->format('j M Y') }}
            @endif
            @if ($property->isMultiUnit())
                · {{ $property->availableUnitCount() }} of {{ $property->units->count() }} units available
            @endif
        </p>
    </div>

    <div class="fees">
        @if ($unit && $unit->hasCompleteFeeBreakdown())
            <p class="lbl">{{ $property->intent === 'sale' ? 'Cost to purchase' : 'Cost to move in' }}</p>

            <div class="feerow">
                <span class="nm">{{ $property->intent === 'sale' ? 'Purchase price' : 'Rent' }}</span>
                <span class="amt">{{ Money::naira($unit->price) }}</span>
            </div>

            @foreach ($unit->feeLines as $fee)
                <div @class(['feerow', 'refund' => $fee->is_refundable])>
                    <span class="nm">
                        {{ $fee->label }}
                        @if ($fee->rateNote())<em>{{ $fee->rateNote() }}</em>@endif
                        @if ($fee->is_refundable)<em>refundable</em>@endif
                    </span>
                    <span class="amt">{{ Money::naira($fee->amount) }}</span>
                </div>
            @endforeach

            <div class="feetotal">
                <span class="nm">{{ $property->intent === 'sale' ? 'Total to purchase' : 'Total to move in' }}</span>
                <span class="amt">{{ Money::naira($unit->moveInTotal()) }}</span>
            </div>

            @if ($unit->refundableTotal() > 0)
                <p class="refundnote">{{ Money::naira($unit->refundableTotal()) }} of this is refundable</p>
            @endif
        @else
            {{-- FR-M7-01 blocks publishing without a breakdown, so this should be
                 unreachable in production. It exists because "should be
                 unreachable" and "is unreachable" are different things, and a
                 silent blank panel would be worse than an honest one. --}}
            <p class="feemissing">This listing has no cost breakdown recorded. Ask the agent for the full move-in cost before paying anything.</p>
        @endif
    </div>

    <div class="agentline">
        <span class="avatar">{{ Str::of($property->lister->name)->explode(' ')->take(2)->map(fn ($w) => Str::substr($w, 0, 1))->implode('') }}</span>
        <div>
            <p class="nm" style="margin:0">
                {{ $property->lister->name }}
                @if ($property->lister->verification_state === 'verified')
                    <x-verified-tick /><span class="sr-only">Verified</span>
                @endif
            </p>
            <p class="rl" style="margin:0">
                {{ $property->lister->verification_state === 'verified' ? 'Verified' : 'Unverified' }}
                {{ str_replace('_', ' ', $property->lister->category) }}
            </p>
        </div>
    </div>

    {{--
        FR-M9-02: the three contact modes, every initiation logged.

        These were four <button type="button"> elements wired to nothing. They
        are forms now, and the lister's number is deliberately NOT in this
        markup — pressing one records the initiation and the server hands back
        the channel, which is what keeps contact details off a page anybody can
        view-source (SEC-10) and behind the account FR-M1-03 requires.
    --}}
    <div class="contact" id="contact">
        @auth
            @php $revealed = session('contact'); @endphp

            @if ($revealed)
                {{-- Written out as well as linked: on a desktop a tel: does
                     nothing useful, and a seeker wants to read the number off
                     the screen and dial it on their phone. --}}
                <div class="revealed">
                    <span class="revealed-label">{{ $revealed['label'] }}</span>
                    @if ($revealed['href'])
                        <a href="{{ $revealed['href'] }}"
                           @if ($revealed['mode'] === 'whatsapp') target="_blank" rel="noopener" @endif
                           class="revealed-value">{{ $revealed['value'] }}</a>
                    @else
                        <span class="revealed-value revealed-none">
                            This lister has not given us a number. Try email.
                        </span>
                    @endif
                </div>
            @endif

            <div class="row">
                <form method="POST" action="{{ route('interact.contact', $property) }}">
                    @csrf
                    <input type="hidden" name="mode" value="phone">
                    <button class="btn btn-brand btn-block"><x-icon name="phone" />Call</button>
                </form>
                <form method="POST" action="{{ route('interact.contact', $property) }}">
                    @csrf
                    <input type="hidden" name="mode" value="whatsapp">
                    <button class="btn btn-wa btn-block">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="width:15px;height:15px"><path d="M12 2a10 10 0 0 0-8.6 15L2 22l5.2-1.4A10 10 0 1 0 12 2Zm5.3 14.1c-.2.6-1.2 1.2-1.7 1.2-.5.1-1 .1-1.6-.1-.4-.1-.9-.3-1.5-.6-2.6-1.1-4.3-3.7-4.4-3.9-.1-.2-1-1.4-1-2.6s.6-1.8.9-2.1c.2-.2.5-.3.6-.3h.5c.2 0 .4 0 .6.4l.8 1.9c.1.2 0 .4-.1.5l-.3.4c-.1.2-.3.3-.1.6.1.3.6 1.1 1.4 1.8 1 .9 1.8 1.1 2 1.2.2.1.4.1.5-.1l.7-.8c.2-.2.3-.2.6-.1l1.8.9c.3.1.4.2.5.3 0 .1 0 .6-.2 1Z"/></svg>
                        WhatsApp
                    </button>
                </form>
            </div>

            <form method="POST" action="{{ route('interact.contact', $property) }}">
                @csrf
                <input type="hidden" name="mode" value="email">
                <button class="btn btn-ghost btn-block">Email the agent</button>
            </form>

            {{-- In-platform scheduling against real availability is FR-M9-12
                 and deferred to R2. Until then this opens an email with the
                 viewing already written, which is what the button says it
                 does — rather than a button that says it and does not. --}}
            <form method="POST" action="{{ route('interact.contact', $property) }}">
                @csrf
                <input type="hidden" name="mode" value="email">
                <input type="hidden" name="intent" value="viewing">
                <button class="btn btn-deep btn-block">Request a viewing</button>
            </form>
        @else
            {{-- FR-M1-03: an account is the price of acting on a listing, not
                 of seeing one. A link to sign in beats a button that fails. --}}
            <a href="{{ route('login') }}" class="btn btn-brand btn-block">
                <x-icon name="phone" />Sign in to contact {{ $property->lister->name }}
            </a>
            <p class="contacthint">
                Browsing needs no account. One is required to call, message or request a
                viewing, so that a lister knows who is asking.
            </p>
        @endauth
    </div>
</div>
