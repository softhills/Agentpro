<?php

namespace App\Support;

/**
 * Every table that holds something about a person, and what happens to it when
 * they ask to be erased (FR-M1-09, NDPA 2023 s. 34).
 *
 * This file exists because the expensive failure in a privacy feature is not a
 * bug in the deletion code — it is a table nobody remembered. A year from now
 * somebody adds `viewings` with a `user_id` on it, the erasure keeps passing
 * its tests, and the platform quietly stops honouring a statutory right. So the
 * manifest is declarative and in one place, and `PersonalDataTest` reads the
 * live schema and fails when a table references `users` without an entry here.
 * Adding a table forces a decision about it; that is the whole design.
 *
 * Three dispositions, and the interesting one is the middle:
 *
 *   DELETE     the rows go. Only for data that exists purely to serve this one
 *              person — what they saved, searched and subscribed to.
 *   ANONYMISE  the row stays, the personal columns are overwritten. For records
 *              the business genuinely needs in aggregate but not attributably.
 *   RETAIN     kept as it is, because the law requires it or because destroying
 *              it would destroy somebody else's record. Section 34(2) of the Act
 *              permits exactly this, and the `why` on each entry is the
 *              justification we would have to give if asked for it.
 *
 * RETAIN is not a loophole to reach for. Everything under it is either money —
 * where FIRS and anti-money-laundering retention obligations run to six years —
 * or the audit trail, which is append-only by design (SEC-12) and records
 * decisions made by staff, not data given to us by the person being erased.
 */
final class PersonalData
{
    public const DELETE    = 'delete';
    public const ANONYMISE = 'anonymise';
    public const RETAIN    = 'retain';

    /**
     * @return array<string, array{label: string, disposal: string, why: string, section: ?string}>
     */
    public static function map(): array
    {
        return [
            'users' => [
                'label'    => 'Your account',
                'disposal' => self::ANONYMISE,
                'why'      => 'Orders, ledger entries and the audit trail all point at this row. '
                             .'Deleting it would orphan records that have to be kept, so every '
                             .'personal field is overwritten and the skeleton stays.',
                'section'  => 'account',
            ],

            // ---------------------------------------------------------- yours alone

            'saved_searches' => [
                'label'    => 'Saved searches',
                'disposal' => self::DELETE,
                'why'      => 'Kept only to alert you. Once you are gone there is nobody to alert.',
                'section'  => 'saved_searches',
            ],
            'saved_search_matches' => [
                'label'    => 'Which listings a saved search already reported',
                'disposal' => self::DELETE,
                'why'      => 'Bookkeeping for the alerts above; goes with them.',
                // Not a section of its own: it is an implementation detail of a
                // saved search and means nothing to a reader on its own.
                'section'  => null,
            ],
            'interactions' => [
                'label'    => 'Saves, hides, ratings, reports and contact taps',
                'disposal' => self::DELETE,
                'why'      => 'A record of what you looked at and liked. Nothing else depends on it.',
                'section'  => 'activity',
            ],
            'push_subscriptions' => [
                'label'    => 'Browsers you allowed notifications in',
                'disposal' => self::DELETE,
                'why'      => 'Deleting them is also what stops the notifications.',
                'section'  => 'devices',
            ],
            'notifications' => [
                'label'    => 'Your notification inbox',
                'disposal' => self::DELETE,
                'why'      => 'Copies of messages already sent to you.',
                'section'  => 'notifications',
            ],
            'sessions' => [
                'label'    => 'Signed-in devices',
                'disposal' => self::DELETE,
                'why'      => 'Holds an IP address and a browser string. Clearing them signs you out everywhere.',
                'section'  => 'sessions',
            ],
            'password_reset_tokens' => [
                'label'    => 'Outstanding password-reset links',
                'disposal' => self::DELETE,
                'why'      => 'Keyed by email address, so it has to be cleared by address rather than by id.',
                // A single-use hash. There is nothing in it a person could read.
                'section'  => null,
            ],
            'pending_listing_alerts' => [
                'label'    => 'Listing changes queued to be announced',
                'disposal' => self::DELETE,
                'why'      => 'Reachable only through your listings, and only until the batching window closes.',
                'section'  => null,
            ],

            // ------------------------------------------------------- kept, unnamed

            'feedback' => [
                'label'    => 'Feedback you sent us',
                'disposal' => self::ANONYMISE,
                'why'      => 'What you told us about the product stays, detached from you. '
                             .'A bug report is about the software, not about a person.',
                'section'  => 'feedback',
            ],
            'sms_messages' => [
                'label'    => 'Texts we sent you',
                'disposal' => self::ANONYMISE,
                'why'      => 'The phone number and the message body are overwritten. The row stays '
                             .'because it carries what the send cost, which is an accounting record.',
                'section'  => 'messages',
            ],
            'payout_accounts' => [
                'label'    => 'Bank accounts you registered for payouts',
                'disposal' => self::ANONYMISE,
                'why'      => 'The account number is cut back to its last four digits — the most that can '
                             .'be removed while a past payout can still be shown to have gone where it went.',
                'section'  => 'bank_accounts',
            ],

            // -------------------------------------------------- kept, and kept named

            'properties' => [
                'label'    => 'Listings you published',
                'disposal' => self::RETAIN,
                'why'      => 'A listing is a commercial record: it may have been paid for, verified, '
                             .'reported or bought from. Erasure requires that none are still live, and '
                             .'what remains is attributed to an account with no personal details on it.',
                'section'  => 'listings',
            ],
            'orders' => [
                'label'    => 'Things you paid for',
                'disposal' => self::RETAIN,
                'why'      => 'A tax record. Nigerian revenue and anti-money-laundering rules require '
                             .'transaction records to be kept for six years, and the Act allows for that '
                             .'(s. 34(2)) rather than overriding another statute.',
                'section'  => 'orders',
            ],
            'refunds' => [
                'label'    => 'Refunds on those payments',
                'disposal' => self::RETAIN,
                'why'      => 'Half of a transaction record is not a transaction record.',
                'section'  => 'refunds',
            ],
            'payouts' => [
                'label'    => 'Money paid out to you',
                'disposal' => self::RETAIN,
                'why'      => 'The same obligation as an order, in the other direction.',
                'section'  => 'payouts',
            ],
            'ledger_entries' => [
                'label'    => 'Your statement',
                'disposal' => self::RETAIN,
                'why'      => 'The record of what was owed and why. Append-only: a correction is another '
                             .'entry, never an edit, which is what makes a disputed balance settleable.',
                'section'  => 'statement',
            ],
            'scan_jobs' => [
                'label'    => '3D captures you were assigned (technicians)',
                'disposal' => self::RETAIN,
                'why'      => 'A record of work carried out at somebody else\'s property, on their listing.',
                'section'  => 'capture_jobs',
            ],
            'technician_slots' => [
                'label'    => 'Capture availability you were booked into (technicians)',
                'disposal' => self::RETAIN,
                'why'      => 'Operational capacity, shared with the bookings made against it.',
                'section'  => null,
            ],
            'realsure_records' => [
                'label'    => 'RealSure checks you signed off (officers)',
                'disposal' => self::RETAIN,
                'why'      => 'A verification badge on a listing means an officer stood behind it. '
                             .'Anonymising that would leave the badge with nobody behind it.',
                'section'  => 'realsure_checks',
            ],
            'data_requests' => [
                'label'    => 'Requests you made to see or erase your data',
                'disposal' => self::RETAIN,
                'why'      => 'The only proof that a request was made and honoured, and the Act gives a '
                             .'deadline to answer one in. A record of erasure that erased itself would '
                             .'leave nothing to show the right was ever respected.',
                'section'  => 'privacy_requests',
            ],
            'audit_events' => [
                'label'    => 'The audit trail',
                'disposal' => self::RETAIN,
                'why'      => 'Append-only by design (SEC-12), and largely a record of decisions staff made '
                             .'about you rather than data you gave us. Because the account row survives '
                             .'anonymisation, the trail still resolves — to an account with no name on it.',
                'section'  => 'audit_trail',
            ],
        ];
    }

    /**
     * The cooling-off period before an erasure runs, with a floor under it.
     *
     * `(int) config(...)` on a missing key is 0, and a zero here does not
     * degrade the feature — it removes it. The pause *is* the control against
     * somebody destroying an account they have taken over, so it must not be
     * possible to switch it off by losing a config key, shipping a stale
     * `config:cache`, or leaving a long-running queue worker holding an older
     * copy of the configuration in memory. Every one of those has happened on
     * this project already.
     *
     * A deliberate reduction is still available down to the floor; below it,
     * the warning message cannot realistically arrive before the deletion does,
     * which makes the whole design dishonest.
     */
    public static function erasureGraceHours(): int
    {
        return max(12, (int) config('agentpro.privacy.erasure_grace_hours'));
    }

    /**
     * How long a built export stays downloadable.
     *
     * Same floor, much lower stakes: a zero here produces a file that is dead
     * the instant it is written, which is merely a support ticket rather than a
     * destroyed account. It gets a floor anyway because a right of access that
     * hands over an already-expired link has not been honoured.
     */
    public static function exportExpiryHours(): int
    {
        return max(1, (int) config('agentpro.privacy.export_expires_hours'));
    }

    /** @return list<string> */
    public static function tablesFor(string $disposal): array
    {
        return array_keys(array_filter(
            self::map(),
            fn (array $entry) => $entry['disposal'] === $disposal
        ));
    }

    /**
     * What the erasure screen shows before anybody commits to anything.
     *
     * Grouped by disposal, because "what disappears" and "what we are keeping,
     * and why" are the two questions somebody actually has — and the second is
     * the one a privacy screen usually ducks.
     *
     * @return array<string, list<array{label: string, why: string}>>
     */
    public static function summary(): array
    {
        $groups = [self::DELETE => [], self::ANONYMISE => [], self::RETAIN => []];

        foreach (self::map() as $entry) {
            // Internal bookkeeping with no section has nothing to say to a
            // reader: it would pad the list and make the real items harder to
            // find.
            if ($entry['section'] === null) {
                continue;
            }

            $groups[$entry['disposal']][] = [
                'label' => $entry['label'],
                'why'   => $entry['why'],
            ];
        }

        return $groups;
    }
}
