@props([
    'label',
    'value',
    'note' => null,
    'caption' => null,
    'progress' => null,
    'target' => null,
    'alert' => false,
])

{{--
    A metric tile carries three things: the number, what it means, and what it
    is supposed to be. A number with no target is trivia — you cannot tell
    whether 27% is good news without knowing the target is 25%.
--}}
<div @class(['metric', 'metric-alert' => $alert])>
    <span class="metric-label">{{ $label }}</span>
    <span class="metric-value">{{ $value }}</span>

    @if ($note)
        <span class="metric-note">{{ $note }}</span>
    @endif

    @if ($progress !== null && $target)
        @php $pct = min(100, round($progress / $target * 100)); @endphp
        <span class="metric-bar" role="img"
              aria-label="{{ $progress }} against a target of {{ $target }}">
            <i style="width:{{ $pct }}%"></i>
        </span>
    @endif

    @if ($caption)
        <span class="metric-caption">{{ $caption }}</span>
    @endif
</div>
