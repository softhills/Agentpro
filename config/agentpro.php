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

        // 'public' in development. In production this becomes the S3-compatible
        // disk so property media is served from a CDN on a domain that cannot
        // execute PHP (SEC-04), and never off the application server (NFR-02).
        'disk' => env('AGENTPRO_MEDIA_DISK', 'public'),

        // Absolute paths to the FFmpeg binaries. Leave null to use PATH.
        // Without them, uploaded video is kept but stays pending, and the
        // transcode job records why rather than failing silently.
        'ffmpeg_path'  => env('FFMPEG_PATH'),
        'ffprobe_path' => env('FFPROBE_PATH'),

        // Per-file ceilings, in kilobytes. Kept below php.ini's
        // upload_max_filesize so the app rejects with a readable message rather
        // than PHP discarding the request body first.
        'max_photo_kb' => 8192,
        'max_video_kb' => 38912,
    ],

    // NFR-12 moderation SLA, in working hours.
    'sla' => [
        'approval_hours' => 6,
        'fraud_report_hours' => 4,
    ],

    /*
     * 3D capture scheduling (M4).
     *
     * Q4 is still open on the reschedule and no-show policy, so the numbers live
     * here rather than being hard-coded into a comparison somewhere — settling
     * the policy should be a config change, not a code change.
     */
    'scan' => [
        'slot_hours'              => 2,
        'reschedule_notice_hours' => 24,
        // How long a paid-but-unscheduled entitlement waits before the lister is
        // chased about it (FR-M4-07).
        'unredeemed_reminder_days' => 3,
    ],

    /*
     * Map (FR-M5-02).
     *
     * The default tile source is OpenStreetMap's own raster service, which is
     * fine for development and NOT acceptable for production: their tile usage
     * policy prohibits heavy or commercial use, and they are entitled to block
     * traffic that ignores it. Before launch, point tile_url at a provider with
     * a contract — MapTiler, Stadia, or self-hosted Protomoaps — and update the
     * attribution to match. See the map section of the README.
     */
    'map' => [
        'tile_url'    => env('AGENTPRO_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'attribution' => env('AGENTPRO_TILE_ATTRIBUTION', '© OpenStreetMap contributors'),
        'max_zoom'    => env('AGENTPRO_MAP_MAX_ZOOM', 19),

        // Opens over Lagos Island / Lekki rather than a national view, because
        // an empty viewport is a worse first impression than no map (risk R9).
        'default_lat'  => env('AGENTPRO_MAP_LAT', 6.4450),
        'default_lng'  => env('AGENTPRO_MAP_LNG', 3.4550),
        'default_zoom' => env('AGENTPRO_MAP_ZOOM', 12),
    ],

    /*
     * Notifications (M9).
     */
    'notifications' => [
        // FR-M9-06: how long changes to one listing accumulate before a single
        // alert goes out. Long enough that an agent correcting several fields
        // produces one message; short enough that a price drop is still news.
        'batch_window_minutes' => env('AGENTPRO_ALERT_WINDOW', 30),
    ],

    /*
     * Web push (FR-M9-08).
     *
     * There is no vendor and no account: the browser hands us an endpoint that
     * already names its own push service, and the same signed request works
     * against Google, Mozilla and Microsoft. The keys below are ours, generated
     * once with `php artisan agentpro:push-keys`, and they identify this
     * application to those services. Changing them invalidates every existing
     * subscription, so they belong in the environment and not in a deployment
     * script that might regenerate them.
     */
    'push' => [
        'public_key'  => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        // How a push service reaches an operator if this application starts
        // misbehaving. Required by RFC 8292, and a real address.
        'subject'     => env('VAPID_SUBJECT', 'mailto:ops@agentpro.ng'),
        // How long the push service should hold a message for a browser that is
        // offline. Two days: a listing decision is still worth seeing on
        // Monday, and nothing here is worth a week.
        'ttl'         => env('AGENTPRO_PUSH_TTL', 172800),
        // Consecutive failures before a subscription is dropped. A push service
        // having a bad afternoon must not unsubscribe the user base.
        'give_up_after' => env('AGENTPRO_PUSH_GIVE_UP', 10),
    ],

    /*
     * SMS (FR-M9-08).
     *
     * The only channel billed per message, and per *segment* rather than per
     * message at that — see App\Support\SmsText. Two segments is the ceiling:
     * an SMS here is a nudge towards the app, and anything longer is an email
     * that went to the wrong place.
     */
    'sms' => [
        'driver'       => env('SMS_DRIVER', 'log'),
        'max_segments' => env('AGENTPRO_SMS_MAX_SEGMENTS', 2),
        'termii' => [
            'api_key'   => env('TERMII_API_KEY'),
            // Must be pre-registered with Termii; an unregistered sender ID is
            // silently replaced or the message is dropped.
            'sender_id' => env('TERMII_SENDER_ID', 'Agentpro'),
            'base_url'  => env('TERMII_BASE_URL', 'https://api.ng.termii.com'),
        ],
    ],

    /*
     * Payouts to listers (FR-M11-07).
     *
     * The only movement of money in this system with a destination somebody
     * chose, so the numbers here are safety limits rather than conveniences.
     */
    'payouts' => [
        // How long new or changed bank details are held before anything can be
        // sent to them. Long enough that the message warning the real owner has
        // been seen; short enough that an honest lister is not left waiting a
        // week. This is the single most effective control against an account
        // takeover, so shortening it is a real decision, not a tuning knob.
        'account_hold_hours' => env('AGENTPRO_PAYOUT_HOLD_HOURS', 24),

        // Transfers cost a flat fee, so a trickle of tiny payouts costs more in
        // charges than it moves.
        'minimum' => env('AGENTPRO_PAYOUT_MINIMUM', 5000),

        // Past this, a transfer has stopped being in progress and started being
        // something to chase.
        'stale_after_days' => env('AGENTPRO_PAYOUT_STALE_DAYS', 3),
    ],

    /*
     * Refunds (FR-M11-05).
     *
     * A refund can only travel back along the transaction that paid it, so the
     * risk is not theft — it is somebody destroying revenue, by accident or
     * otherwise. Hence a ceiling rather than a lock: under it, an admin refunds
     * without ceremony; over it, a second admin has to agree. The default sits
     * above a capture (₦150,000) and below a RealSure verification (₦350,000),
     * so the everyday case is one click and the expensive one is not.
     */
    'refunds' => [
        'dual_approval_above' => env('AGENTPRO_REFUND_APPROVAL_ABOVE', 200000),
        // Nigerian card refunds genuinely take days. Past this, a refund has
        // stopped being in progress and started being something to chase.
        'stale_after_days'    => env('AGENTPRO_REFUND_STALE_DAYS', 7),
    ],

    /*
     * Settlement reconciliation (FR-M11-06).
     */
    'settlement' => [
        // A window, not a day: providers backfill and amend, and a job that only
        // ever looks at yesterday never sees the amendment.
        'lookback_days'      => env('AGENTPRO_SETTLEMENT_LOOKBACK', 14),
        // How long a paid order may go unsettled before it is a finding.
        // Paystack settles NGN on T+1 working days; this allows for a weekend
        // and a public holiday without crying wolf every Monday.
        'grace_days'         => env('AGENTPRO_SETTLEMENT_GRACE', 4),
        // Rounding slack in naira. Above this, the figures genuinely disagree.
        'variance_tolerance' => env('AGENTPRO_SETTLEMENT_TOLERANCE', 1.00),
    ],

    /*
     * Access and erasure (FR-M1-09, NDPA 2023).
     *
     * @see \App\Support\PersonalData for what an erasure does to each table and
     *      the justification for everything it keeps.
     */
    'privacy' => [
        /*
         * How long a scheduled erasure waits before it runs.
         *
         * Longer than the payout hold (24h), and for a different reason. A
         * stolen login is worth money if it can redirect a payout, so that hold
         * only has to outlast the attacker's patience. An erasure is worth
         * nothing but harm — which is exactly what makes it the thing a
         * vindictive attacker reaches for — and it cannot be undone afterwards,
         * so this has to outlast a weekend away from a phone. Shortening it is
         * a real decision, not a tuning knob.
         */
        'erasure_grace_hours' => env('AGENTPRO_ERASURE_GRACE_HOURS', 72),

        // How long a built export stays downloadable. An uncollected export is
        // a complete copy of somebody's account sitting on a disk, so this is
        // short on purpose — and asking again costs nothing.
        'export_expires_hours' => env('AGENTPRO_EXPORT_EXPIRY_HOURS', 72),

        /*
         * Exports are written here. 'local' is Laravel's private disk — outside
         * the web root, with no URL of its own — and that is the requirement,
         * not the convenience. Pointing this at a public disk would publish
         * every export to anyone who could guess a uuid.
         */
        'export_disk' => env('AGENTPRO_EXPORT_DISK', 'local'),

        // Shown in the export file and on the privacy screen. NDPA s. 31
        // requires a contact point for data-subject requests.
        'contact' => env('AGENTPRO_PRIVACY_CONTACT', 'privacy@agentpro.ng'),
    ],

    'paystack' => [
        'secret_key'   => env('PAYSTACK_SECRET_KEY'),
        'public_key'   => env('PAYSTACK_PUBLIC_KEY'),
        'base_url'     => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        // Development fallback secret for the fake gateway's signatures.
        'fake_secret'  => env('PAYSTACK_FAKE_SECRET', 'fake_secret'),
    ],
];
