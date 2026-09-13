{{--
    A funnel, drawn honestly.

    The obvious version computes "x% did not get this far" between every pair of
    steps, and it is wrong the moment a later step is not a subset of an earlier
    one. On the seeker side it always is: a listing can be opened from Google,
    a shared WhatsApp link or a saved-search alert without any search happening
    on this site at all, so listing views routinely exceed searches — and the
    naive arithmetic produces "-1150% did not get this far", which is not a
    smaller number, it is a category error.

    So drop-off is shown only between steps where one genuinely contains the
    other, and where it does not, the screen says why the count went up instead
    of inventing a percentage.

    @param $steps  list of ['name' => string, 'count' => int]
--}}
@php
    $peak = max(1, collect($steps)->max('count'));
@endphp

<ol class="funnel">
    @foreach ($steps as $i => $step)
        @php
            $previous = $i === 0 ? null : $steps[$i - 1]['count'];
            $contained = $previous !== null && $previous > 0 && $step['count'] <= $previous;
        @endphp
        <li>
            <span class="funnelbar" style="--w:{{ round($step['count'] / $peak * 100) }}%"></span>
            <span class="funnelname">{{ $step['name'] }}</span>
            <span class="funnelnum">{{ number_format($step['count']) }}</span>
            <span class="funneldrop">
                @if ($contained)
                    {{ round((1 - $step['count'] / $previous) * 100) }}% did not get this far
                @elseif ($previous !== null && $step['count'] > $previous)
                    more than the step above — {{ $arrivals ?? 'people also reach this step directly' }}
                @endif
            </span>
        </li>
    @endforeach
</ol>
