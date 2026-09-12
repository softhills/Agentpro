@props(['seed' => 0])
{{--
  Deterministic placeholder artwork, keyed off the property id so a listing keeps
  the same look across pages. Replaced by the real cover image as soon as media
  uploads land.
--}}
@php
    $palettes = [
        ['#CBD7E6','#A9BDD4','#8FA6C2','#F2E3C2','#7E93AE'],
        ['#D8CFC2','#C0B3A1','#A2937E','#F5E9CE','#8C7F6C'],
        ['#C9DCCF','#A6C2AF','#89A995','#EFEAC8','#75917F'],
        ['#DAD3E2','#BEB3CC','#9E90B0','#F1E7CF','#8A7C9C'],
        ['#B9C9DE','#97ADC8','#7C93B4','#EFDFBC','#6C82A0'],
    ];
    $p = $palettes[$seed % count($palettes)];
    $style = sprintf('--p1:%s;--p2:%s;--p3:%s;--p4:%s;--p5:%s', ...$p);
@endphp
<svg class="ph" style="{{ $style }}" role="img" aria-label="Property image placeholder"><use href="#ph"/></svg>
