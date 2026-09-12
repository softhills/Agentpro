@props(['name'])
{{-- Inline icon set. Inlined rather than sprited: there are few enough that a
     sprite request costs more than the bytes save. --}}
@php
    $icons = [
        'pin'     => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'bed'     => '<path d="M3 18v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6M3 18h18M6 10V7a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v3"/>',
        'bath'    => '<path d="M4 12h16v3a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4v-3ZM7 12V6a2 2 0 0 1 4 0"/>',
        'toilet'  => '<path d="M6 4h12v7a6 6 0 0 1-12 0V4Z"/><path d="M9 21h6"/>',
        'area'    => '<path d="M4 4h16v16H4z"/><path d="M4 10h6V4"/>',
        'heart'   => '<path d="M12 20s-7-4.3-7-9a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 4.7-7 9-7 9Z"/>',
        'compare' => '<path d="M4 7h11M4 7l3-3M4 7l3 3M20 17H9m11 0-3-3m3 3-3 3"/>',
        'check'   => '<path d="m4 12 5.5 5.5L20 7"/>',
        'cube'    => '<path d="M12 3 3 8v8l9 5 9-5V8l-9-5Z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'video'   => '<rect x="2" y="6" width="14" height="12" rx="2"/><path d="m16 11 6-3.5v9L16 13z"/>',
        'globe'   => '<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/>',
        'street'  => '<circle cx="12" cy="9" r="3"/><path d="M6 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/>',
        'plan'    => '<path d="M4 4h16v17H4z"/><path d="M4 10h9M13 10v11"/>',
        'photo'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m5 17 5-5 4 4 2-2 3 3"/>',
        'drone'   => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'doc'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/>',
        'shield'  => '<path d="M12 3 4 6v6c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V6Z"/><path d="m9 12 2 2 4-4"/>',
        'home'    => '<path d="M3 21V8l9-5 9 5v13"/><path d="M9 21v-7h6v7"/>',
        'bell'    => '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'search'  => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'draw'    => '<path d="M4 18 18 4l2 2L6 20l-3 1Z"/>',
        'phone'   => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a1 1 0 0 1-1 1A16 16 0 0 1 4 5a1 1 0 0 1 1-1Z"/>',
        'minus'   => '<circle cx="12" cy="12" r="9"/><path d="M8 12h8"/>',
        'naira'   => '<path d="M7 19V5l10 14V5"/><path d="M4 10h16M4 14h16"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
    ];
@endphp
<svg {{ $attributes->merge(['viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '2', 'aria-hidden' => 'true']) }}>
    {!! $icons[$name] ?? '' !!}
</svg>
