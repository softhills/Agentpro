<?php

/**
 * Product configuration.
 *
 * Everything Operations or Finance changes without a deployment lives here or
 * in the database — never inline in code (PRD §17, "configuration over code").
 * Prices are read server-side at checkout; the client never sends an amount.
 */
return [
    // FR-M2-14: window in which a listing still reads as "New".
    'freshness_days' => env('AGENTPRO_FRESHNESS_DAYS', 7),

    // FR-M2-08: default display duration for a new listing.
    'display_duration_days' => env('AGENTPRO_DISPLAY_DAYS', 60),

    // FR-M11-03: versioned so an order records what it was charged under.
    'prices' => [
        'version' => '2026-09',
        'scan_3d' => env('AGENTPRO_PRICE_SCAN_3D', 150000),
        'realsure' => env('AGENTPRO_PRICE_REALSURE', 350000),
    ],

    // NFR-02: enforced as budgets, not aspirations.
    'media' => [
        'min_photos' => 5,
        'max_photos' => 30,
        'max_video_seconds' => 180,
        'video_renditions' => ['720p', '480p'],
        // Q18 is open: 'self' streams from our CDN, 'embed' defers to Vimeo/YouTube.
        'video_strategy' => env('AGENTPRO_VIDEO_STRATEGY', 'self'),
    ],

    // NFR-12 moderation SLA, in working hours.
    'sla' => [
        'approval_hours' => 6,
        'fraud_report_hours' => 4,
    ],
];
