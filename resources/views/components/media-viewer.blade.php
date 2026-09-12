@props(['property'])

@php
    use App\Enums\MediaKind;

    // Only tabs with approved media are rendered — an empty tab is worse than a
    // missing one.
    $available = [];
    foreach (MediaKind::viewerOrder() as $kind) {
        $assets = $property->mediaOfKind($kind);
        if ($assets->isNotEmpty()) {
            $available[$kind->value] = ['kind' => $kind, 'assets' => $assets];
        }
    }

    // Video opens by default where one exists: it is the medium a seeker is most
    // likely to watch end to end, and the cheapest to deliver of the rich ones.
    $default = array_key_exists('video', $available) ? 'video'
        : (array_key_first($available) ?? null);

    $icons = [
        'photo' => 'photo', 'video' => 'video', 'tour_3d' => 'cube',
        'pano_360' => 'globe', 'street_view' => 'street', 'floor_plan' => 'plan',
    ];
@endphp

@if ($default)
<div class="viewer" data-viewer>
    <div class="vtabs" role="tablist" aria-label="Property media">
        @foreach ($available as $key => $entry)
            @php $first = $entry['assets']->first(); @endphp
            <button type="button" role="tab"
                    data-stg="{{ $key }}"
                    aria-selected="{{ $key === $default ? 'true' : 'false' }}">
                <x-icon :name="$icons[$key] ?? 'photo'" />
                {{ $entry['kind']->label() }}
                @if ($key === 'photo')
                    <span style="opacity:.6">{{ $entry['assets']->count() }}</span>
                @elseif ($first?->durationLabel())
                    <span style="opacity:.6">{{ $first->durationLabel() }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <div class="vstage">
        @foreach ($available as $key => $entry)
            @php
                $kind  = $entry['kind'];
                $first = $entry['assets']->first();
            @endphp

            <div class="stg" data-stg="{{ $key }}" @if ($key !== $default) hidden @endif>

                @if ($key === 'photo')
                    <div class="photogrid">
                        @foreach ($entry['assets']->take(5) as $i => $photo)
                            <span>
                                <x-placeholder :seed="$property->id + $i" />
                                @if ($i === 4 && $entry['assets']->count() > 5)
                                    <span class="more">+{{ $entry['assets']->count() - 5 }} photos</span>
                                @endif
                            </span>
                        @endforeach
                    </div>
                @else
                    <x-placeholder :seed="$property->id" />

                    <span class="vchip">
                        <x-icon :name="$icons[$key] ?? 'photo'" />
                        {{ $kind === MediaKind::Video ? 'Walkthrough video' : $kind->label() }}
                    </span>

                    @if ($first?->durationLabel())
                        <span class="vdur">{{ $first->durationLabel() }}</span>
                    @endif

                    {{--
                        NFR-02. Every rich medium is poster-gated: nothing streams,
                        loads or autoplays until the seeker asks for it. On a
                        metered connection a full listing must be readable without
                        spending a naira on media nobody requested.
                    --}}
                    @if ($kind->isGated())
                        <button type="button" class="gate"
                                data-media="{{ $first?->uuid }}"
                                data-kind="{{ $key }}">
                            <span>
                                <span class="play">
                                    @if ($kind === MediaKind::Video)
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                                    @else
                                        <x-icon :name="$icons[$key] ?? 'cube'" stroke-width="2.2" />
                                    @endif
                                </span>
                                <strong>
                                    @switch($kind)
                                        @case(MediaKind::Video) Play walkthrough @break
                                        @case(MediaKind::Tour3d) Open 3D tour @break
                                        @case(MediaKind::Pano360) Drag to look around @break
                                        @default Open Street View
                                    @endswitch
                                </strong>
                                <small>
                                    @if ($kind === MediaKind::Video)
                                        streams on tap · {{ implode(' / ', array_keys($first?->renditions ?? ['720p' => null])) }} · no autoplay
                                    @elseif ($first?->bytes)
                                        {{ $first->provider ? Str::title($first->provider).' · ' : '' }}loads on tap · ~{{ round($first->bytes / 1048576, 1) }} MB
                                    @else
                                        loads on tap
                                    @endif
                                </small>
                            </span>
                        </button>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    {{-- FR-M3-10: provenance per medium. A seeker must be able to tell a
         technician's capture from a phone video an agent shot. --}}
    <p class="vcap" data-vcap>
        @php $d = $available[$default]['assets']->first(); @endphp
        <span data-vcap-main>{{ $available[$default]['kind']->label() }}@if ($d?->durationLabel()) · {{ $d->durationLabel() }}@endif</span>
        <span class="src" data-vcap-src>{{ $d?->provenance() }}</span>
    </p>
</div>

@push('scripts')
<script>
(function () {
    document.querySelectorAll('[data-viewer]').forEach(function (viewer) {
        var caption = viewer.querySelector('[data-vcap-main]');
        var source  = viewer.querySelector('[data-vcap-src]');
        var meta    = @json(collect($available)->map(fn ($e) => [
            'label' => $e['kind']->label()
                . ($e['assets']->first()?->durationLabel() ? ' · '.$e['assets']->first()->durationLabel() : ''),
            'src'   => $e['assets']->first()?->provenance() ?? '',
        ]));

        viewer.querySelectorAll('.vtabs button').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var want = tab.dataset.stg;

                viewer.querySelectorAll('.vtabs button').forEach(function (t) {
                    t.setAttribute('aria-selected', String(t === tab));
                });
                viewer.querySelectorAll('.stg').forEach(function (s) {
                    s.hidden = s.dataset.stg !== want;
                });

                if (meta[want]) {
                    caption.textContent = meta[want].label;
                    source.textContent  = meta[want].src;
                }
            });
        });

        // The gate is where the real embed gets swapped in. Until the Matterport
        // and video players are wired, it stays a no-op rather than a broken
        // iframe — but the contract is fixed: nothing loads before this click.
        viewer.querySelectorAll('.gate').forEach(function (gate) {
            gate.addEventListener('click', function () {
                gate.setAttribute('aria-busy', 'true');
            });
        });
    });
})();
</script>
@endpush
@endif
