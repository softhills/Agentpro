@extends('layouts.app')

@section('title', 'Review: '.$property->title.' — Agentpro')

@section('content')
@php use App\Support\Money; @endphp

<div class="container formwrap" style="max-width:1180px">
    <a href="{{ route('admin.queue') }}" class="backlink">&larr; Back to the queue</a>

    <div class="dashhead">
        <div>
            <h1 style="margin-bottom:4px">{{ $property->title }}</h1>
            <p>
                {{ $property->address_line }}, {{ $property->area?->name ?? $property->city }}
                @if ($property->what3words)
                    · <span style="font-family:var(--m);color:#C8102E;font-size:12.5px">{{ $property->what3words }}</span>
                @endif
            </p>
        </div>
    </div>

    <x-form-errors />

    {{-- FR-M2-12: duplicate flags lead, because they are the one thing that
         changes how everything below should be read. --}}
    @if ($duplicates->isNotEmpty())
        <div class="alert alert-warn">
            <strong>{{ $duplicates->count() }} possible {{ Str::plural('duplicate', $duplicates->count()) }} nearby</strong>
            <ul>
                @foreach ($duplicates as $dup)
                    <li>
                        <a href="{{ route('admin.review', $dup['property']) }}" style="font-weight:700;text-decoration:underline">
                            {{ $dup['property']->title }}
                        </a>
                        — {{ implode(' · ', $dup['reasons']) }}
                        <span style="color:var(--slate)">({{ $dup['property']->lister->name }})</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="reviewgrid">
        <div>
            <section class="formsec">
                <h2>Submitted content</h2>
                <dl class="kvlist">
                    <div><dt>Type</dt><dd>{{ ucfirst($property->listing_type) }} · {{ $property->intent === 'sale' ? 'For sale' : 'For rent' }}</dd></div>
                    <div><dt>Build status</dt><dd>{{ str_replace('_', ' ', ucfirst($property->build_status)) }}</dd></div>
                    <div><dt>Finish</dt><dd>{{ $property->finish ? ucfirst($property->finish) : '—' }}</dd></div>
                    <div><dt>Coordinates</dt><dd style="font-family:var(--m);font-size:12.5px">{{ $property->lat }}, {{ $property->lng }}</dd></div>
                    <div><dt>Website</dt><dd>{{ $property->website_url ?? '—' }}</dd></div>
                </dl>
                <p style="font-family:var(--i);font-size:14px;color:var(--slate);line-height:1.6;margin:10px 0 0">
                    {{ $property->description ?: 'No description supplied.' }}
                </p>
            </section>

            <section class="formsec">
                <h2>Media <span class="mutedcount">{{ $property->media->count() }} assets</span></h2>
                {{--
                    The real photographs, all of them.

                    This strip rendered <x-placeholder> — not as a fallback, but
                    hard-coded, for every listing. A moderation console that
                    cannot show the moderator the photographs is not a
                    moderation console: "photographs missing, unusable or not of
                    this property" is one of the rejection reasons on this very
                    screen, and it was being judged against decorative artwork.

                    Not capped at eight either. A listing may carry thirty, and
                    the one that is a photograph of a different building is not
                    reliably in the first eight — a reviewer who sees a subset
                    is worse off than one who knows they are seeing a subset.

                    Each opens the full-size original in a new tab, because 88px
                    of a room is enough to count the photographs and not enough
                    to judge one.
                --}}
                <div class="mediastrip">
                    @forelse ($property->media->where('kind', 'photo') as $m)
                        <a href="{{ $m->url('1600') ?? $m->url() }}" target="_blank" rel="noopener"
                           class="mediathumb" title="Open full size">
                            <x-property-image :asset="$m" :seed="$property->id"
                                              :alt="'Photograph submitted for '.$property->title"
                                              rendition="400" sizes="88px" />
                        </a>
                    @empty
                        <p class="fhint">No photographs supplied.</p>
                    @endforelse
                </div>
                <p class="fhint">
                    @foreach ($property->media->groupBy('kind') as $kind => $group)
                        {{ $group->count() }} {{ str_replace('_', ' ', $kind) }}@if (! $loop->last) · @endif
                    @endforeach
                </p>
            </section>

            <section class="formsec">
                <h2>Declared title</h2>
                @forelse ($property->titleClaims as $claim)
                    <div class="titlerow">
                        <span class="nm">{{ $claim->label() }}</span>
                        <span @class(['st', 'st-ok' => $claim->isAvailable(), 'st-prog' => ! $claim->isAvailable()])>
                            {{ $claim->isAvailable() ? 'Available' : 'In progress' }}
                        </span>
                    </div>
                @empty
                    <p class="fhint">No title declared.</p>
                @endforelse
            </section>

            @if ($property->amenities->isNotEmpty())
                <section class="formsec">
                    <h2>Amenities</h2>
                    <p class="fhint">{{ $property->amenities->pluck('name')->implode(' · ') }}</p>
                </section>
            @endif
        </div>

        <div>
            {{-- The fee breakdown sits in the decision column deliberately. It is
                 the most common reason a listing is returned, so it should be
                 under the moderator's eye as they choose. --}}
            <section class="formsec">
                <h2>Cost to move in</h2>
                @if ($unit && $unit->hasCompleteFeeBreakdown())
                    <div class="fees" style="padding:0">
                        <div class="feerow">
                            <span class="nm">{{ $property->intent === 'sale' ? 'Price' : 'Rent' }}</span>
                            <span class="amt">{{ Money::naira($unit->price) }}</span>
                        </div>
                        @foreach ($unit->feeLines as $fee)
                            <div @class(['feerow', 'refund' => $fee->is_refundable])>
                                <span class="nm">{{ $fee->label }}@if ($fee->is_refundable)<em>refundable</em>@endif</span>
                                <span class="amt">{{ Money::naira($fee->amount) }}</span>
                            </div>
                        @endforeach
                        <div class="feetotal">
                            <span class="nm">Total</span>
                            <span class="amt">{{ Money::naira($unit->moveInTotal()) }}</span>
                        </div>
                    </div>
                @else
                    <p class="feemissing">No cost breakdown. This should not have passed submission — worth checking before approving.</p>
                @endif
            </section>

            <section class="formsec">
                <h2>Lister</h2>
                <div class="agentline" style="padding:0;border:0">
                    <span class="avatar">{{ $property->lister->initials() }}</span>
                    <div>
                        <p class="nm" style="margin:0">
                            {{ $property->lister->name }}
                            @if ($property->lister->isVerified())<x-verified-tick />@endif
                        </p>
                        <p class="rl" style="margin:0">
                            {{ $property->lister->categoryLabel() }} ·
                            {{ $property->lister->isVerified() ? 'verified' : $property->lister->verification_state }}
                        </p>
                    </div>
                </div>
            </section>

            <section class="formsec formsec-required">
                <h2>Decision</h2>

                {{-- Hidden once the listing is closed. Approving a sold property
                     would republish it and start a fresh display period, which
                     is not a decision anybody comes to this screen to make —
                     the way back is the relist below. --}}
                @unless ($property->lifecycle_state->isClosed())
                <form method="POST" action="{{ route('admin.approve', $property) }}">
                    @csrf
                    <button type="submit" class="btn btn-green btn-block">Approve and publish</button>
                </form>
                <p class="fhint">
                    Publishes immediately for {{ config('agentpro.display_duration_days') }} days.
                    The display period starts now, not at submission.
                </p>

                <hr class="decisionrule">

                <form method="POST" action="{{ route('admin.reject', $property) }}" class="stack">
                    @csrf
                    <div class="fieldset">
                        <label class="flabel" for="reason_code">Return to lister — reason</label>
                        <select id="reason_code" name="reason_code" class="finput" required>
                            @foreach ($rejectReasons as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="fieldset">
                        <label class="flabel" for="note">What the lister must fix</label>
                        <textarea id="note" name="note" rows="3" class="finput" required
                                  placeholder="Be specific — this is the only thing they see."></textarea>
                    </div>
                    <button type="submit" class="btn btn-ghost btn-block">Return to lister</button>
                </form>
                @endunless

                {{--
                    FR-M2-07 / FR-M2-09: off the market, with the outcome named.

                    The same question the lister's own form asks, because it is
                    the same question. An admin who knows the flat was let has no
                    business recording that as an anonymous "unpublished" — the
                    archive would then undercount exactly the transactions staff
                    were closest to. The reason list is the wider one: a
                    moderator may record a finding a lister may not.
                --}}
                @if ($property->lifecycle_state->canBeUnlisted())
                    <hr class="decisionrule">
                    <form method="POST" action="{{ route('admin.unlist', $property) }}" class="stack">
                        @csrf
                        <div class="fieldset">
                            <label class="flabel" for="outcome">Take off the market — what happened</label>
                            <select id="outcome" name="outcome" class="finput" required>
                                @foreach ($closeOutcomes as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="fieldset">
                            <label class="flabel" for="unpub">If other — reason</label>
                            <select id="unpub" name="reason_code" class="finput">
                                <option value="">—</option>
                                @foreach ($unpublishReasons as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <p class="fhint">
                            Sold and rented appear in the public
                            <a href="{{ route('pages.closed') }}">sold and let archive</a>.
                            Anything else ends the listing's public life quietly.
                        </p>
                        <button type="submit" class="btn btn-ghost btn-block" style="color:#8E2B2B">Take off the market</button>
                    </form>
                @endif

                @if ($property->lifecycle_state->canBeRelisted())
                    <hr class="decisionrule">
                    <form method="POST" action="{{ route('admin.relist', $property) }}">
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-block">Put back on the market</button>
                        <p class="fhint">
                            {{-- Concatenated rather than wrapped in @if: a
                                 directive on its own line leaves whitespace,
                                 and "Sold on 7 Aug 2026 ." is a typo. --}}
                            Closed as {{ $property->lifecycle_state->label() }}{{ $property->closed_at ? ' on '.$property->closed_at->format('j M Y') : '' }}.
                            Relisting does not extend the display period.
                        </p>
                    </form>
                @endif
            </section>
        </div>
    </div>
</div>
@endsection
