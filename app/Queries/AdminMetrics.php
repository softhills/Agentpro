<?php

namespace App\Queries;

use App\Enums\LifecycleState;
use App\Models\Interaction;
use App\Models\Order;
use App\Models\Property;
use App\Models\Refund;
use App\Models\ScanJob;
use App\Models\Settlement;
use App\Models\User;
use App\Queries\Funnel;
use Illuminate\Support\Facades\DB;

/**
 * The numbers behind the admin dashboard.
 *
 * Every figure here answers one of the objectives in PRD §2 rather than being a
 * count that happened to be easy to produce. A dashboard of arbitrary totals
 * tells an operator nothing about whether the business is working; these are
 * the measures the release is supposed to be judged on, so they are the ones on
 * the screen.
 */
class AdminMetrics
{
    /** O1 — can seekers trust what they see? */
    public function trust(): array
    {
        $live = Property::where('lifecycle_state', LifecycleState::Published->value)->count();
        $verified = Property::where('lifecycle_state', LifecycleState::Published->value)
            ->whereNotNull('realsure_verified_at')->count();

        return [
            'live'            => $live,
            'realsure'        => $verified,
            'realsure_share'  => $live > 0 ? round($verified / $live * 100) : 0,
            'target_share'    => 25,
        ];
    }

    /** O2 — does remote viewing replace a physical trip? */
    public function immersive(): array
    {
        $withTour = Property::where('lifecycle_state', LifecycleState::Published->value)
            ->whereHas('media', fn ($q) => $q->where('kind', 'tour_3d')->where('moderation_state', 'approved'))
            ->count();

        $withVideo = Property::where('lifecycle_state', LifecycleState::Published->value)
            ->whereHas('media', fn ($q) => $q->where('kind', 'video')->where('moderation_state', 'approved'))
            ->count();

        /*
         * The objective is "remote viewing replaces a physical trip", and the
         * count of listings carrying a tour does not measure that at all — a
         * tour nobody opens replaces nothing. Dwell is the half that does, and
         * until M13 there was no way to know it.
         */
        return [
            'tours'        => $withTour,
            'videos'       => $withVideo,
            'target_tours' => 400,
            'dwell'        => (new Funnel)->tourDwell(),
        ];
    }

    /**
     * O3 — is fee opacity actually eliminated?
     *
     * Publishing is supposed to be blocked without a complete breakdown, so a
     * non-zero count here is not a statistic, it is a bug report.
     */
    public function feeTransparency(): array
    {
        $live = Property::where('lifecycle_state', LifecycleState::Published->value)->count();

        $missing = Property::where('lifecycle_state', LifecycleState::Published->value)
            ->whereHas('units', fn ($q) => $q->whereDoesntHave('feeLines'))
            ->count();

        return [
            'live'      => $live,
            'missing'   => $missing,
            'compliant' => $live > 0 ? round(($live - $missing) / $live * 100) : 100,
        ];
    }

    /** O4 — supply. */
    public function supply(): array
    {
        return [
            'verified_listers' => User::where('verification_state', 'verified')
                ->where('category', '!=', 'seeker')->count(),
            'pending_listers'  => User::where('verification_state', 'pending')->count(),
            'seekers'          => User::where('category', 'seeker')->count(),
            'target_listers'   => 1200,
        ];
    }

    /**
     * O7 — moderation throughput against the NFR-12 SLA.
     *
     * Median rather than mean: one listing left over a weekend drags an average
     * far enough to hide a queue that is otherwise healthy.
     */
    public function moderation(): array
    {
        $waiting = Property::whereIn('lifecycle_state', [
            LifecycleState::Submitted->value,
            LifecycleState::UnderReview->value,
        ])->get(['submitted_at']);

        $slaHours = (int) config('agentpro.sla.approval_hours');

        $decided = DB::table('audit_events')
            ->join('properties', 'properties.id', '=', 'audit_events.subject_id')
            ->whereIn('audit_events.action', ['listing.approved', 'listing.rejected'])
            ->where('audit_events.subject_type', 'Property')
            ->whereNotNull('properties.submitted_at')
            ->where('audit_events.created_at', '>=', now()->subDays(30))
            ->selectRaw('TIMESTAMPDIFF(HOUR, properties.submitted_at, audit_events.created_at) AS hours')
            ->pluck('hours')
            ->filter(fn ($h) => $h !== null && $h >= 0)
            ->sort()
            ->values();

        return [
            'queue_depth'  => $waiting->count(),
            // Order matters: Carbon 3 returns a signed difference, so
            // now()->diffInHours($past) is negative and would never exceed the
            // SLA. Measured from the submission forward instead.
            'breaching'    => $waiting->filter(
                fn ($p) => $p->submitted_at && $p->submitted_at->diffInHours(now()) > $slaHours
            )->count(),
            'median_hours' => $decided->isEmpty() ? null : (int) $decided[intdiv($decided->count(), 2)],
            'sla_hours'    => $slaHours,
            'decided_30d'  => $decided->count(),
        ];
    }

    /** O6 — does the 3D upgrade pay for itself? */
    public function revenue(): array
    {
        $paid = Order::where('state', 'paid');

        return [
            'orders_paid'   => (clone $paid)->count(),
            'gross'         => (float) (clone $paid)->sum('amount'),
            'gross_30d'     => (float) (clone $paid)->where('paid_at', '>=', now()->subDays(30))->sum('amount'),
            'refunded'      => (float) Order::sum('refunded_amount'),
            'pending'       => Order::where('state', 'pending')->count(),
            // FR-M4-07: paid, nothing booked. Money taken, nothing delivered —
            // the one number on this page that should always be zero.
            'unredeemed'    => Order::where('item_type', 'scan_3d')->where('state', 'paid')
                ->whereDoesntHave('scanJob')->count(),
        ];
    }

    /**
     * Has the money actually arrived, and can all of it be accounted for?
     *
     * A different question from revenue(), which reports what was charged. These
     * are the figures Finance is asked about, and all but two of them are
     * supposed to be zero.
     */
    public function money(): array
    {
        $grace = (int) config('agentpro.settlement.grace_days');
        $stale = (int) config('agentpro.refunds.stale_after_days');

        $unsettled = Order::whereIn('state', ['paid', 'partially_refunded', 'refunded'])
            ->whereNull('settlement_id')
            ->whereNotNull('paid_at')
            ->where('paid_at', '<', now()->subDays($grace));

        return [
            // Money in the bank this system cannot account for: either no
            // order carries the reference, or the order it belongs to was never
            // marked paid. Read from the reconciliation rollup rather than
            // recounted, so the dashboard cannot disagree with the run.
            'orphans'        => (int) Settlement::sum('unmatched_count'),
            'orphan_amount'  => (float) Settlement::sum('unmatched_amount'),

            // The mirror image: orders we told people succeeded, that no payout
            // ever contained.
            'unsettled'        => (clone $unsettled)->count(),
            'unsettled_amount' => (float) (clone $unsettled)->sum('amount'),

            'discrepancies' => Settlement::where('reconciliation_state', 'discrepancy')->count(),

            'settled_30d' => (float) Settlement::where('status', 'success')
                ->where('settlement_date', '>=', now()->subDays(30))->sum('effective_amount'),
            'fees_30d'    => (float) Settlement::where('status', 'success')
                ->where('settlement_date', '>=', now()->subDays(30))->sum('total_fees'),

            'refunds_awaiting'  => Refund::where('state', 'requested')->count(),
            'refunds_in_flight' => Refund::where('state', 'submitted')->count(),
            'refunds_stuck'     => Refund::where('state', 'submitted')
                ->where('submitted_at', '<', now()->subDays($stale))->count(),
            'refunds_failed'    => Refund::where('state', 'failed')
                ->where('updated_at', '>=', now()->subDays(30))->count(),

            // The job going quiet is itself a finding, and the only one that
            // nothing else on this page would ever reveal — every other figure
            // here would simply stop changing, which looks like good news.
            'last_reconciled' => $this->lastReconciliation(),
        ];
    }

    private function lastReconciliation(): ?\Illuminate\Support\Carbon
    {
        $at = DB::table('audit_events')
            ->where('action', 'settlement.reconciled')
            ->max('created_at');

        return $at ? \Illuminate\Support\Carbon::parse($at) : null;
    }

    /** Field operations — capacity is the constraint on the only paid feature. */
    public function operations(): array
    {
        return [
            'scheduled'      => ScanJob::where('state', 'scheduled')->count(),
            'awaiting_capture' => ScanJob::whereIn('state', ['scheduled', 'rescheduled'])
                ->where('scheduled_for', '<', now())->count(),
            'live'           => ScanJob::where('state', 'live')->count(),
            'slots_open'     => DB::table('technician_slots')
                ->whereColumn('booked', '<', 'capacity')
                ->whereDate('slot_date', '>=', now()->toDateString())->count(),
        ];
    }

    /** O8 — is fraud contained? */
    public function integrity(): array
    {
        $live = max(1, Property::where('lifecycle_state', LifecycleState::Published->value)->count());
        $reports = Interaction::where('kind', 'report')->count();

        return [
            'reports'        => $reports,
            'reports_30d'    => Interaction::where('kind', 'report')
                ->where('created_at', '>=', now()->subDays(30))->count(),
            'per_thousand'   => round($reports / $live * 1000, 1),
            'target'         => 5,
            'suspended'      => User::where('verification_state', 'suspended')->count(),
        ];
    }

    /** Engagement — the demand side, which supply decisions depend on. */
    public function engagement(): array
    {
        return [
            'saves'          => Interaction::where('kind', 'save')->count(),
            'contacts'       => Interaction::where('kind', 'contact')->count(),
            'saved_searches' => DB::table('saved_searches')->where('frequency', '!=', 'off')->count(),
            'contact_rate'   => $this->contactRate(),
        ];
    }

    /**
     * O5 — detail views that turn into a contact attempt.
     *
     * This returned null for the life of the project before M13, and not
     * because there was no traffic: `view_count` was a column nothing ever
     * incremented, so the denominator was structurally zero and the objective
     * the release is judged on could not be read. It is a real number now.
     *
     * Counted over the reporting window rather than all time. A lifetime rate
     * moves so slowly that a change in the product is invisible in it, which
     * makes it useless as the thing you steer by.
     */
    private function contactRate(): ?float
    {
        return (new Funnel)->seeker()['contact_rate'];
    }

    /** Recent activity, for the "what just happened" panel. */
    public function recentActivity(int $limit = 12)
    {
        return DB::table('audit_events')
            ->leftJoin('users', 'users.id', '=', 'audit_events.actor_id')
            ->orderByDesc('audit_events.id')
            ->limit($limit)
            ->get([
                'audit_events.action',
                'audit_events.subject_type',
                'audit_events.subject_id',
                'audit_events.created_at',
                'users.name as actor',
            ]);
    }
}
