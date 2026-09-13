<?php

namespace App\Actions;

use App\Models\Property;
use App\Models\RealsureRecord;
use App\Models\User;
use App\Notifications\RealsureDecided;
use App\Support\Audit;
use App\Support\Vocab;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The RealSure record, and the badge that rests on it (FR-M6-01 to FR-M6-03).
 *
 * The badge is the product. Everything else on this platform can be wrong in a
 * way that costs somebody a wasted afternoon; this can be wrong in a way that
 * costs somebody their savings, because "Agentpro has conducted extra
 * verification on the property" is the sentence a buyer will rely on when they
 * stop asking their own questions. So the controls here are stricter than
 * anywhere except payouts, and for the same reason: the damage is not
 * recoverable afterwards.
 *
 * THE BADGE IS GRANTED, NOT DERIVED. It would be easy to light it up the moment
 * a component is ticked, and wrong: the ten components include photography and
 * floor plans, which are services the lister bought rather than anything that
 * was checked. A badge earned by a drone flight would say "verified" about a
 * listing nobody verified. Granting is a separate, deliberate act, and it is
 * refused unless real verification work sits underneath it.
 *
 * IT COMES OFF THE SAME WAY IT GOES ON. A title search that turns out to have
 * been read wrong has to be revocable, with a reason, and the lister has to be
 * told — they paid for this, and a badge that silently disappears from a live
 * listing is worse than one that never appeared.
 *
 * EVERY CHANGE IS ATTRIBUTED. FR-M6-03 asks for a completion date and an
 * officer of record in the audit log, which is what makes the badge defensible
 * a year later when somebody asks who said the title was clean.
 */
class RecordRealsureCheck
{
    /**
     * Record one component against one listing.
     *
     * Upserts rather than appends: the table is unique on (property, component)
     * because "was the title checked" has exactly one answer at any moment. The
     * history of how that answer changed lives in the audit trail, which is
     * append-only and cannot be quietly rewritten.
     */
    public function record(
        Property $property,
        string $component,
        User $officer,
        bool $completed,
        ?Carbon $completedOn = null,
        ?string $evidenceRef = null,
        ?string $notes = null,
    ): RealsureRecord {
        if (! array_key_exists($component, Vocab::REALSURE_COMPONENTS)) {
            throw new RuntimeException('There is no RealSure component called "'.$component.'".');
        }

        /*
         * A completed check needs a date, and the date must not be in the
         * future. Both look like form validation and are not: this row is the
         * evidence that a specific check happened on a specific day, and a
         * completion dated next Tuesday is not evidence of anything.
         */
        if ($completed) {
            $completedOn ??= now();

            if ($completedOn->startOfDay()->isAfter(now()->startOfDay())) {
                throw new RuntimeException('A check cannot be recorded as completed on a future date.');
            }
        }

        $before = RealsureRecord::where('property_id', $property->id)
            ->where('component', $component)->first();

        $record = RealsureRecord::updateOrCreate(
            ['property_id' => $property->id, 'component' => $component],
            [
                'completed'    => $completed,
                // Cleared when a check is withdrawn, so a component marked "not
                // commissioned" cannot keep a completion date from last month.
                'completed_on' => $completed ? $completedOn : null,
                'officer_id'   => $completed ? $officer->id : null,
                'evidence_ref' => $evidenceRef,
                'notes'        => $notes,
            ]
        );

        Audit::record('realsure.component_recorded', $property, [
            'component' => $component,
            'was'       => $before?->completed ? 'completed' : 'not completed',
            'was_on'    => $before?->completed_on?->toDateString(),
        ], [
            'component' => $component,
            'now'       => $completed ? 'completed' : 'not completed',
            'on'        => $record->completed_on?->toDateString(),
            'evidence'  => $evidenceRef,
        ], $officer->id);

        /*
         * A granted badge whose verification work has just been withdrawn is a
         * badge with nothing behind it. Rather than leave that to whoever
         * remembers, the grant is re-tested here and drops if it no longer
         * holds — with a reason the lister can read.
         */
        if (! $completed && $property->isRealsureVerified() && $this->blockers($property->fresh()) !== []) {
            $this->revoke(
                $property->fresh(),
                $officer,
                'A verification check this badge rested on was withdrawn.',
            );
        }

        return $record;
    }

    /**
     * What stands between this listing and the badge.
     *
     * Sentences rather than codes, because their only destination is the screen
     * an officer is looking at when they wonder why the button is disabled.
     *
     * @return list<string>
     */
    public function blockers(Property $property): array
    {
        $blockers = [];

        if ($property->lifecycle_state->value !== 'published') {
            $blockers[] = 'This listing is not published, so a badge on it would not be visible '
                .'to anybody. Verify it once it is live.';
        }

        $completed = $property->realsureRecords
            ->where('completed', true)
            ->pluck('component')
            ->all();

        $verification = array_intersect($completed, Vocab::REALSURE_VERIFICATION);
        $minimum = (int) config('agentpro.realsure.minimum_verification_components');

        if (count($verification) < $minimum) {
            $blockers[] = 'The badge needs at least '.$minimum.' completed '
                .\Illuminate\Support\Str::plural('verification check', $minimum)
                .' behind it; there '.(count($verification) === 1 ? 'is' : 'are').' '
                .count($verification).'. Photography and floor plans are services the lister '
                .'bought, not things anybody checked, so they do not count towards it.';
        }

        /*
         * Title is singled out because it is the whole problem. Land title
         * opacity is the reason this module exists, and the standing disclaimer
         * on every listing names *this* component by name — it tells a buyer
         * that Agentpro asserts nothing about the title unless this specific
         * check was done. A badge granted without it would contradict the
         * disclaimer printed underneath it.
         */
        if (config('agentpro.realsure.require_title_verification')
            && ! in_array('title_verification', $completed, true)) {
            $blockers[] = 'Title verification has not been completed. The disclaimer on every '
                .'listing names that check specifically, so the badge cannot go on without it.';
        }

        return $blockers;
    }

    /** FR-M6-01: only ever by an officer, never derived and never self-applied. */
    public function grant(Property $property, User $officer): Property
    {
        $property->load('realsureRecords');

        if ($blockers = $this->blockers($property)) {
            throw new RuntimeException($blockers[0]);
        }

        if ($property->isRealsureVerified()) {
            return $property;
        }

        DB::transaction(function () use ($property, $officer) {
            $property->forceFill(['realsure_verified_at' => now()])->save();

            Audit::record('realsure.badge_granted', $property, [], [
                'components' => $property->realsureRecords
                    ->where('completed', true)->pluck('component')->values()->all(),
            ], $officer->id);
        });

        $property->lister?->notify(new RealsureDecided($property, granted: true));

        return $property->refresh();
    }

    /**
     * Take the badge off.
     *
     * The reason is required and is not decoration. A revocation is a statement
     * that something the platform previously asserted was wrong, and the person
     * who has to answer for it in six months is not necessarily the person
     * pressing the button today.
     */
    public function revoke(Property $property, User $officer, string $why): Property
    {
        if (trim($why) === '') {
            throw new RuntimeException('Say why the badge is being removed.');
        }

        if (! $property->isRealsureVerified()) {
            return $property;
        }

        DB::transaction(function () use ($property, $officer, $why) {
            Audit::record('realsure.badge_revoked', $property, [
                'verified_at' => $property->realsure_verified_at?->toIso8601String(),
            ], ['reason' => $why], $officer->id);

            $property->forceFill(['realsure_verified_at' => null])->save();
        });

        /*
         * The lister paid for this. A badge that vanishes from their live
         * listing without a word is how a support ticket becomes a complaint,
         * and they may have a refund claim — which is a conversation, not
         * something to bury.
         */
        $property->lister?->notify(new RealsureDecided($property, granted: false, reason: $why));

        return $property->refresh();
    }
}
