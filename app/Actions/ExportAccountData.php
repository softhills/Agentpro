<?php

namespace App\Actions;

use App\Models\DataRequest;
use App\Models\User;
use App\Support\Audit;
use App\Support\PersonalData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The right of access, as a file (FR-M1-09, NDPA 2023 ss. 34 and 38).
 *
 * Two things make this more than a database dump.
 *
 * The Act asks for data in a "structured, commonly used and machine-readable
 * format", which JSON satisfies — but the point of the right is that a person
 * can find out what is held about them, and a dump of raw columns does not tell
 * anybody anything. So every section is written for a reader: the keys are
 * words rather than column names, the sections say what they are, and each one
 * carries the same explanation of why it is kept that the erasure screen shows.
 * The same manifest drives both, so they cannot drift apart.
 *
 * The other is what is left out. An export must not become a way to extract
 * things about *other* people: the phone number of a seeker who tapped Contact
 * is that seeker's data, not the lister's, and password hashes, verification
 * references and provider tokens are ours. Each query below selects columns
 * explicitly for exactly that reason — `select('*')` here would leak by default
 * the next time a column is added, which is the failure mode worth designing
 * out rather than remembering.
 */
class ExportAccountData
{
    /**
     * Build the file and mark the request ready.
     *
     * Written to the private disk. There is no public path to it and no
     * predictable name; it is reachable only through a signed route that checks
     * the request belongs to whoever is asking.
     */
    public function __invoke(DataRequest $request): DataRequest
    {
        $user = $request->user;

        $payload = $this->build($user);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Named by the request uuid rather than by anything about the person:
        // a directory listing of exports should not itself be a list of who
        // asked for one.
        $path = 'exports/'.$request->uuid.'.json';

        Storage::disk($this->disk())->put($path, $json);

        $request->update([
            'state'      => 'ready',
            'file_path'  => $path,
            'file_bytes' => strlen($json),
            'expires_at' => now()->addHours(PersonalData::exportExpiryHours()),
        ]);

        Audit::record('data_request.ready', $request, [], [
            'bytes' => $request->file_bytes,
        ], $user->id);

        return $request->refresh();
    }

    /**
     * Everything held about one person, section by section.
     *
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        return [
            'about_this_file' => [
                'generated_at' => now()->toIso8601String(),
                'about'        => 'Everything Agentpro holds about this account, under section 38 of the '
                                 .'Nigeria Data Protection Act 2023.',
                'not_included' => 'Personal details belonging to other people — for example the contact '
                                 .'details of a seeker who enquired about one of your listings — are '
                                 .'their data rather than yours, and are not in this file. Nor are '
                                 .'security values such as password hashes and payment provider tokens.',
                'questions'    => config('agentpro.privacy.contact'),
            ],

            // What each section is and why we have it. Straight from the same
            // manifest the erasure runs on, so the two can never disagree.
            'what_each_section_is' => $this->sectionNotes(),

            'account'          => $this->account($user),
            'sessions'         => $this->sessions($user),
            'devices'          => $this->devices($user),
            'saved_searches'   => $this->savedSearches($user),
            'activity'         => $this->activity($user),
            'notifications'    => $this->notifications($user),
            'messages'         => $this->messages($user),
            'feedback'         => $this->feedback($user),
            'listings'         => $this->listings($user),
            'orders'           => $this->orders($user),
            'refunds'          => $this->refunds($user),
            'bank_accounts'    => $this->bankAccounts($user),
            'payouts'          => $this->payouts($user),
            'statement'        => $this->statement($user),
            'capture_jobs'     => $this->captureJobs($user),
            'realsure_checks'  => $this->realsureChecks($user),
            'privacy_requests' => $this->privacyRequests($user),
            'audit_trail'      => $this->auditTrail($user),
        ];
    }

    /** @return array<string, array{holds: string, why_we_keep_it: string}> */
    private function sectionNotes(): array
    {
        $notes = [];

        foreach (PersonalData::map() as $entry) {
            if ($entry['section'] === null) {
                continue;
            }

            // Several tables can share a section — saved searches and their
            // match bookkeeping, for instance. First one in wins; the rest are
            // internals with nothing extra to tell a reader.
            $notes[$entry['section']] ??= [
                'holds'           => $entry['label'],
                'why_we_keep_it'  => $entry['why'],
            ];
        }

        return $notes;
    }

    // ------------------------------------------------------------------ sections

    private function account(User $user): array
    {
        return [
            'reference'           => $user->uuid,
            'name'                => $user->name,
            'email'               => $user->email,
            'email_confirmed_at'  => $this->at($user->email_verified_at),
            'phone'               => $user->phone,
            'phone_confirmed_at'  => $this->at($user->phone_verified_at),
            'account_type'        => $user->categoryLabel(),
            'organisation'        => $user->organisation?->name,
            'role_in_organisation' => $user->org_role,
            'identity_check'      => [
                'state'       => $user->verification_state,
                'verified_at' => $this->at($user->verified_at),
                // The vendor is named; the reference is not. NFR-04 already has
                // us discard the document number after the check, and the
                // vendor's handle for it is a key into their system, not a fact
                // about the person.
                'checked_by'  => $user->verification_vendor,
            ],
            'commute_destination' => $user->commute_label === null ? null : [
                'label'     => $user->commute_label,
                'latitude'  => $user->commute_lat,
                'longitude' => $user->commute_lng,
            ],
            'notification_settings' => $user->notification_preferences,
            'registered_at'         => $this->at($user->created_at),
        ];
    }

    private function sessions(User $user): array
    {
        return DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get(['ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($row) => [
                'ip_address'    => $row->ip_address,
                'browser'       => $row->user_agent,
                'last_active_at' => $this->at(\Carbon\CarbonImmutable::createFromTimestamp($row->last_activity)),
            ])
            ->all();
    }

    private function devices(User $user): array
    {
        return DB::table('push_subscriptions')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get(['user_agent', 'created_at', 'last_used_at'])
            ->map(fn ($row) => [
                'browser'       => $row->user_agent,
                'allowed_at'    => $row->created_at,
                'last_used_at'  => $row->last_used_at,
                // The endpoint and its keys are omitted deliberately: together
                // they are a capability to send this person notifications, and
                // handing that out in a file is worse than withholding it.
                'note'          => 'The delivery address and encryption keys for this browser are '
                                  .'withheld — anyone holding them could send you notifications.',
            ])
            ->all();
    }

    private function savedSearches(User $user): array
    {
        return DB::table('saved_searches')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['name', 'criteria', 'bounds', 'frequency', 'last_run_at', 'created_at'])
            ->map(fn ($row) => [
                'name'        => $row->name,
                'looking_for' => json_decode($row->criteria, true),
                'map_area'    => json_decode((string) $row->bounds, true),
                'alerts'      => $row->frequency,
                'last_run_at' => $row->last_run_at,
                'created_at'  => $row->created_at,
            ])
            ->all();
    }

    private function activity(User $user): array
    {
        return DB::table('interactions')
            ->join('properties', 'properties.id', '=', 'interactions.property_id')
            ->where('interactions.user_id', $user->id)
            ->orderBy('interactions.id')
            ->get([
                'interactions.kind', 'interactions.rating', 'interactions.contact_mode',
                'interactions.reason_code', 'interactions.note', 'interactions.created_at',
                'properties.title', 'properties.uuid',
            ])
            ->map(fn ($row) => array_filter([
                'what'         => $row->kind,
                'listing'      => $row->title,
                'listing_ref'  => $row->uuid,
                'rating'       => $row->rating,
                'contacted_by' => $row->contact_mode,
                'reason'       => $row->reason_code,
                'your_note'    => $row->note,
                'at'           => $row->created_at,
            ], fn ($value) => $value !== null))
            ->all();
    }

    private function notifications(User $user): array
    {
        return DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->orderBy('created_at')
            ->get(['data', 'read_at', 'created_at'])
            ->map(fn ($row) => [
                'message' => json_decode($row->data, true),
                'read_at' => $row->read_at,
                'sent_at' => $row->created_at,
            ])
            ->all();
    }

    private function messages(User $user): array
    {
        return DB::table('sms_messages')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['to_phone', 'body', 'state', 'notification_type', 'created_at'])
            ->map(fn ($row) => [
                'to'      => $row->to_phone,
                'text'    => $row->body,
                'about'   => $row->notification_type,
                'outcome' => $row->state,
                'sent_at' => $row->created_at,
            ])
            ->all();
    }

    private function feedback(User $user): array
    {
        return DB::table('feedback')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['subject', 'body', 'page', 'resolved_at', 'created_at'])
            ->map(fn ($row) => [
                'subject'     => $row->subject,
                'you_wrote'   => $row->body,
                'from_page'   => $row->page,
                'resolved_at' => $row->resolved_at,
                'sent_at'     => $row->created_at,
            ])
            ->all();
    }

    private function listings(User $user): array
    {
        return DB::table('properties')
            ->where('lister_id', $user->id)
            ->orderBy('id')
            ->get([
                'uuid', 'title', 'description', 'listing_type', 'intent',
                'address_line', 'city', 'state', 'lat', 'lng', 'what3words',
                'lifecycle_state', 'published_at', 'expires_at', 'view_count', 'created_at',
            ])
            ->map(fn ($row) => [
                'reference'   => $row->uuid,
                'title'       => $row->title,
                'description' => $row->description,
                'type'        => $row->listing_type,
                'for'         => $row->intent,
                'where'       => array_filter([
                    'address'     => $row->address_line,
                    'city'        => $row->city,
                    'state'       => $row->state,
                    'latitude'    => $row->lat,
                    'longitude'   => $row->lng,
                    'what3words'  => $row->what3words,
                ], fn ($value) => $value !== null),
                'status'       => $row->lifecycle_state,
                'published_at' => $row->published_at,
                'expires_at'   => $row->expires_at,
                'times_viewed' => $row->view_count,
                'created_at'   => $row->created_at,
            ])
            ->all();
    }

    private function orders(User $user): array
    {
        return DB::table('orders')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get([
                'uuid', 'item_type', 'amount', 'currency', 'state',
                'paystack_channel', 'paid_at', 'refunded_amount', 'created_at',
            ])
            ->map(fn ($row) => [
                'reference'     => $row->uuid,
                'for'           => $row->item_type,
                'amount'        => $row->amount,
                'currency'      => $row->currency,
                'status'        => $row->state,
                'paid_by'       => $row->paystack_channel,
                'paid_at'       => $row->paid_at,
                'refunded'      => $row->refunded_amount,
                'ordered_at'    => $row->created_at,
            ])
            ->all();
    }

    private function refunds(User $user): array
    {
        return DB::table('refunds')
            ->join('orders', 'orders.id', '=', 'refunds.order_id')
            ->where('orders.user_id', $user->id)
            ->orderBy('refunds.id')
            ->get([
                'orders.uuid as order_uuid', 'refunds.amount', 'refunds.reason',
                'refunds.state', 'refunds.processed_at', 'refunds.created_at',
            ])
            ->map(fn ($row) => [
                'on_order'     => $row->order_uuid,
                'amount'       => $row->amount,
                'reason'       => $row->reason,
                'status'       => $row->state,
                'paid_back_at' => $row->processed_at,
                'requested_at' => $row->created_at,
            ])
            ->all();
    }

    private function bankAccounts(User $user): array
    {
        return DB::table('payout_accounts')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['bank_name', 'account_number', 'account_name', 'is_active', 'created_at'])
            ->map(fn ($row) => [
                'bank'         => $row->bank_name,
                // Masked even here. This file travels — to a laptop, a phone,
                // sometimes an inbox — and a full account number in it buys an
                // attacker more than it tells the owner, who knows their own.
                'account'      => str_repeat('*', max(0, strlen($row->account_number) - 4))
                                 .substr($row->account_number, -4),
                'account_name' => $row->account_name,
                'in_use'       => (bool) $row->is_active,
                'added_at'     => $row->created_at,
            ])
            ->all();
    }

    private function payouts(User $user): array
    {
        return DB::table('payouts')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['uuid', 'amount', 'currency', 'state', 'paid_at', 'failure_reason', 'created_at'])
            ->map(fn ($row) => array_filter([
                'reference'    => $row->uuid,
                'amount'       => $row->amount,
                'currency'     => $row->currency,
                'status'       => $row->state,
                'paid_at'      => $row->paid_at,
                'why_it_failed' => $row->failure_reason,
                'requested_at' => $row->created_at,
            ], fn ($value) => $value !== null))
            ->all();
    }

    private function statement(User $user): array
    {
        return DB::table('ledger_entries')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['direction', 'amount', 'currency', 'kind', 'memo', 'created_at'])
            ->map(fn ($row) => [
                'in_or_out' => $row->direction,
                'amount'    => $row->amount,
                'currency'  => $row->currency,
                'what_for'  => $row->kind,
                'memo'      => $row->memo,
                'at'        => $row->created_at,
            ])
            ->all();
    }

    private function captureJobs(User $user): array
    {
        return DB::table('scan_jobs')
            ->join('properties', 'properties.id', '=', 'scan_jobs.property_id')
            ->where('scan_jobs.technician_id', $user->id)
            ->orderBy('scan_jobs.id')
            ->get([
                'scan_jobs.uuid', 'scan_jobs.state', 'scan_jobs.scheduled_for',
                'scan_jobs.attended_at', 'properties.title',
            ])
            ->map(fn ($row) => [
                'reference'     => $row->uuid,
                'listing'       => $row->title,
                'status'        => $row->state,
                'scheduled_for' => $row->scheduled_for,
                'attended_at'   => $row->attended_at,
            ])
            ->all();
    }

    private function realsureChecks(User $user): array
    {
        return DB::table('realsure_records')
            ->join('properties', 'properties.id', '=', 'realsure_records.property_id')
            ->where('realsure_records.officer_id', $user->id)
            ->orderBy('realsure_records.id')
            ->get([
                'realsure_records.component', 'realsure_records.notes',
                'realsure_records.created_at', 'properties.title',
            ])
            ->map(fn ($row) => [
                'listing'   => $row->title,
                'component' => $row->component,
                'your_note' => $row->notes,
                'at'        => $row->created_at,
            ])
            ->all();
    }

    private function privacyRequests(User $user): array
    {
        return DB::table('data_requests')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['uuid', 'kind', 'state', 'ip', 'created_at', 'completed_at'])
            ->map(fn ($row) => [
                'reference'    => $row->uuid,
                'asked_for'    => $row->kind,
                'status'       => $row->state,
                'from_ip'      => $row->ip,
                'requested_at' => $row->created_at,
                'answered_at'  => $row->completed_at,
            ])
            ->all();
    }

    private function auditTrail(User $user): array
    {
        /*
         * Events where this person was the actor, not every event that mentions
         * them. A moderator's rejection note about somebody else's listing is
         * the moderator's record and the platform's, and handing it over here
         * would turn the right of access into a way of reading staff notes.
         */
        return DB::table('audit_events')
            ->where('actor_id', $user->id)
            ->orderBy('id')
            ->get(['action', 'subject_type', 'ip', 'created_at'])
            ->map(fn ($row) => [
                'what'    => $row->action,
                'on'      => $row->subject_type,
                'from_ip' => $row->ip,
                'at'      => $row->created_at,
            ])
            ->all();
    }

    // ------------------------------------------------------------------- helpers

    private function at(mixed $value): ?string
    {
        return $value?->toIso8601String();
    }

    private function disk(): string
    {
        return (string) config('agentpro.privacy.export_disk');
    }
}
