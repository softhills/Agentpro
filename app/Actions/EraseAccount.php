<?php

namespace App\Actions;

use App\Models\DataRequest;
use App\Models\User;
use App\Notifications\AccountErasureScheduled;
use App\Support\Audit;
use App\Support\Ledger;
use App\Support\PersonalData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The right to erasure (FR-M1-09, NDPA 2023 s. 34).
 *
 * Three decisions shape everything below.
 *
 * IT IS NOT A DELETE. Orders, refunds, payouts, ledger entries and the audit
 * trail all hang off the account row and all have to be kept — revenue and
 * anti-money-laundering rules require transaction records for six years, and
 * s. 34(2) of the Act allows for exactly that rather than overriding another
 * statute. Dropping the row would either cascade those away or orphan them, and
 * an audit trail whose actor cannot be resolved has stopped being one. So the
 * row survives with every personal field overwritten, and what points at it
 * resolves to an account with nothing personal on it. `PersonalData` is where
 * that judgement is recorded table by table, with the reason we would have to
 * give if a regulator asked for it.
 *
 * IT WAITS. This is the most destructive thing an account can do to itself and
 * it cannot be undone, which makes it worth something to somebody who has
 * stolen a login — not to steal, but to destroy. So it is held for a
 * cooling-off period and the warning goes to the contact details already on
 * file, the same shape of control as a change of payout bank details, for the
 * same reason: the message has to reach the person being harmed.
 *
 * IT REFUSES OUT LOUD. Erasing somebody who is still owed money, still has a
 * listing on the market or has paid for a capture that has not happened would
 * destroy their side of an obligation. Those are refused with the specific
 * thing to settle and where to settle it, rather than with a generic failure —
 * a privacy right people cannot work out how to exercise is not one they have.
 */
class EraseAccount
{
    /**
     * Everything that has to be settled before this account can be erased.
     *
     * Returned as sentences rather than codes because their only destination is
     * a screen. Each one names the specific thing and where to deal with it.
     *
     * @return list<string>
     */
    public function blockers(User $user): array
    {
        $blockers = [];

        /*
         * Staff first, and it is the one blocker with no self-service remedy.
         * Removing a moderator or a finance admin is an offboarding — their
         * access has to be withdrawn, their open decisions handed over — and
         * none of that belongs behind a button on the person's own settings
         * page.
         */
        if ($user->is_staff) {
            $blockers[] = 'This account has staff access, so it cannot be closed from here. '
                .'Ask an administrator to remove the access first.';
        }

        $live = DB::table('properties')
            ->where('lister_id', $user->id)
            ->whereNull('deleted_at')
            ->whereIn('lifecycle_state', ['submitted', 'under_review', 'published'])
            ->count();

        if ($live > 0) {
            $blockers[] = $live === 1
                ? 'One of your listings is still live or waiting for review. Unpublish or '
                  .'withdraw it first — seekers cannot be left with a listing nobody can answer for.'
                : $live.' of your listings are still live or waiting for review. Unpublish or '
                  .'withdraw them first — seekers cannot be left with listings nobody can answer for.';
        }

        $balance = Ledger::balanceFor($user);

        if ($balance > 0) {
            $blockers[] = 'You are still owed '.\App\Support\Money::naira($balance)
                .'. Withdraw it before closing the account — we will not quietly keep it.';
        }

        $payouts = DB::table('payouts')
            ->where('user_id', $user->id)
            ->whereIn('state', ['requested', 'submitted'])
            ->count();

        if ($payouts > 0) {
            $blockers[] = 'A payout is still on its way to your bank. It has to land or fail '
                .'before the account can close, because afterwards there would be nobody to tell.';
        }

        $refunds = DB::table('refunds')
            ->join('orders', 'orders.id', '=', 'refunds.order_id')
            ->where('orders.user_id', $user->id)
            ->whereIn('refunds.state', ['requested', 'submitted'])
            ->count();

        if ($refunds > 0) {
            $blockers[] = 'A refund on one of your payments has not finished. '
                .'It has to reach you before the account can close.';
        }

        $undelivered = DB::table('scan_jobs')
            ->join('properties', 'properties.id', '=', 'scan_jobs.property_id')
            ->where('properties.lister_id', $user->id)
            ->whereIn('scan_jobs.state', ['paid', 'scheduled', 'captured', 'processing'])
            ->count();

        if ($undelivered > 0) {
            $blockers[] = 'You have paid for a 3D capture that has not been delivered. '
                .'Let it finish, or ask us to cancel and refund it, before closing the account.';
        }

        return $blockers;
    }

    /**
     * Schedule an erasure.
     *
     * Nothing is destroyed here. The cooling-off period is the control, and it
     * is only a control if the person is told it has started.
     */
    public function request(User $user, ?string $ip = null, ?string $userAgent = null): DataRequest
    {
        if ($existing = $this->openErasureFor($user)) {
            return $existing;
        }

        if ($blockers = $this->blockers($user)) {
            throw new RuntimeException($blockers[0]);
        }

        $request = DataRequest::create([
            'uuid'        => (string) Str::uuid(),
            'user_id'     => $user->id,
            'kind'        => 'erasure',
            'state'       => 'pending',
            'ip'          => $ip,
            'user_agent'  => Str::limit((string) $userAgent, 250, ''),
            'executes_at' => now()->addHours(PersonalData::erasureGraceHours()),
        ]);

        Audit::record('data_request.erasure_scheduled', $request, [], [
            'executes_at' => $request->executes_at->toIso8601String(),
        ], $user->id);

        /*
         * To the details already on file. If this is an attacker who has just
         * scheduled the destruction of somebody's account, this message is the
         * only thing standing between them and it — so it ignores quiet hours
         * and carries no one-tap unsubscribe.
         */
        $user->notify(new AccountErasureScheduled($request));

        return $request;
    }

    /** Stop a scheduled erasure. Possible right up to the moment it runs. */
    public function cancel(DataRequest $request, ?int $actorId = null): DataRequest
    {
        if (! $request->isCancellable()) {
            throw new RuntimeException('That request has already run and cannot be called back.');
        }

        $request->update(['state' => 'cancelled', 'cancelled_at' => now()]);

        Audit::record('data_request.cancelled', $request, [], [
            'kind' => $request->kind,
        ], $actorId);

        return $request;
    }

    /**
     * Carry it out.
     *
     * Everything inside one transaction. A half-erased account — personal
     * fields gone, saved searches still arriving by email — would be worse than
     * either outcome, and impossible to reason about afterwards.
     */
    public function execute(DataRequest $request): DataRequest
    {
        $user = $request->user;

        if ($user === null || $user->anonymised_at !== null) {
            $request->update([
                'state' => 'completed',
                'completed_at' => now(),
                'note' => 'This account had already been erased.',
            ]);

            return $request;
        }

        /*
         * Re-checked at execution, not only at request time. Three days is long
         * enough for a listing to be republished or a payout to be requested,
         * and running anyway would destroy the record of an open obligation.
         * A refusal here is not an ending: the screen shows what to settle, and
         * asking again afterwards costs one form submission.
         */
        if ($blockers = $this->blockers($user)) {
            $request->update(['state' => 'refused', 'note' => $blockers[0]]);

            Audit::record('data_request.refused', $request, [], ['reason' => $blockers[0]], $user->id);

            return $request;
        }

        // Read before the row is overwritten: it is the key into
        // password_reset_tokens, which has no user id on it.
        $email = $user->email;

        DB::transaction(function () use ($user, $email, $request) {
            $this->deleteWhatIsOnlyTheirs($user, $email);
            $this->anonymiseWhatIsKept($user);
            $this->retireOtherRequests($user, $request);
            $this->anonymiseAccount($user);
        });

        $request->update(['state' => 'completed', 'completed_at' => now()]);

        /*
         * Recorded against the anonymised user, which is the point: the audit
         * trail has to be able to show that a request was honoured without
         * re-introducing the person it was for.
         */
        Audit::record('data_request.erased', $request, [], [
            'tables_cleared'    => count(PersonalData::tablesFor(PersonalData::DELETE)),
            'tables_anonymised' => count(PersonalData::tablesFor(PersonalData::ANONYMISE)),
        ], $user->id);

        return $request;
    }

    // ------------------------------------------------------------------ internals

    private function openErasureFor(User $user): ?DataRequest
    {
        return DataRequest::where('user_id', $user->id)
            ->where('kind', 'erasure')
            ->where('state', 'pending')
            ->latest('id')
            ->first();
    }

    /** @see PersonalData::DELETE */
    private function deleteWhatIsOnlyTheirs(User $user, string $email): void
    {
        // saved_search_matches has no user column; it goes with its parent on
        // the cascade, which is why the manifest lists it without a section.
        DB::table('saved_searches')->where('user_id', $user->id)->delete();
        DB::table('interactions')->where('user_id', $user->id)->delete();
        DB::table('push_subscriptions')->where('user_id', $user->id)->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->delete();

        /*
         * Alerts about this person's listings that have not gone out yet. The
         * listings themselves are retained, so these would be too by default —
         * but announcing a change to a listing whose owner has just closed
         * their account is the wrong message at the worst possible moment.
         */
        DB::table('pending_listing_alerts')
            ->whereIn('property_id', DB::table('properties')->where('lister_id', $user->id)->select('id'))
            ->whereNull('dispatched_at')
            ->delete();
    }

    /** @see PersonalData::ANONYMISE */
    private function anonymiseWhatIsKept(User $user): void
    {
        // The content of a bug report is about the software. Detaching it is
        // enough; deleting it would throw away something we were told.
        DB::table('feedback')->where('user_id', $user->id)->update(['user_id' => null]);

        DB::table('sms_messages')->where('user_id', $user->id)->update([
            'to_phone' => 'redacted',
            'body'     => 'redacted',
        ]);

        foreach (DB::table('payout_accounts')->where('user_id', $user->id)->get() as $account) {
            $masked = str_repeat('•', max(0, strlen($account->account_number) - 4))
                .substr($account->account_number, -4);

            try {
                DB::table('payout_accounts')->where('id', $account->id)->update([
                    'account_number' => $masked,
                    'account_name'   => 'Deleted account',
                    'recipient_code' => null,
                ]);
            } catch (QueryException $e) {
                /*
                 * Two accounts at the same bank ending in the same four digits
                 * collapse to the same masked value and collide on the unique
                 * index. The surviving row is then byte-identical to this one,
                 * so dropping it loses nothing — and letting the collision
                 * abort the erasure would be far worse than losing a duplicate
                 * of a masked number.
                 */
                DB::table('payout_accounts')->where('id', $account->id)->delete();
            }
        }
    }

    /**
     * Exports belonging to this person.
     *
     * An export file is a complete copy of everything they just asked to have
     * erased, sitting on disk behind a link that has not expired yet. Leaving
     * one there would undo the erasure at the first URL somebody still had.
     */
    private function retireOtherRequests(User $user, DataRequest $current): void
    {
        $others = DataRequest::where('user_id', $user->id)
            ->where('id', '!=', $current->id)
            ->get();

        foreach ($others as $other) {
            if ($other->file_path) {
                Storage::disk((string) config('agentpro.privacy.export_disk'))->delete($other->file_path);
            }

            $other->update([
                'state'     => $other->isOpen() ? 'cancelled' : $other->state,
                'file_path' => null,
                'expires_at' => null,
                'cancelled_at' => $other->isOpen() ? now() : $other->cancelled_at,
            ]);
        }
    }

    /** @see PersonalData::ANONYMISE — the account row itself. */
    private function anonymiseAccount(User $user): void
    {
        /*
         * .invalid is reserved by RFC 2606 and can never resolve, so the
         * placeholder that keeps the unique index satisfied cannot accidentally
         * become a deliverable address. The id keeps it unique without
         * containing anything about the person.
         */
        $user->forceFill([
            'name'                   => 'Deleted account',
            'email'                  => 'deleted-'.$user->id.'@accounts.invalid',
            'email_verified_at'      => null,
            'password'               => Hash::make(Str::random(64)),
            'remember_token'         => null,
            'phone'                  => null,
            'phone_verified_at'      => null,
            // The vendor's handle for an identity check is a key into their
            // system. The vendor's name is not, and is worth keeping as a
            // record of how the check was done.
            'verification_reference' => null,
            'notification_preferences' => null,
            'commute_label'          => null,
            'commute_lat'            => null,
            'commute_lng'            => null,
            // Belt and braces. `blockers()` refuses a staff account outright,
            // so this should be unreachable — but an anonymised row that still
            // carried a staff role would be a credential with no owner, and
            // that is not a risk worth leaving to an upstream check.
            'is_staff'               => false,
            'staff_role'             => null,
            'anonymised_at'          => now(),
        ])->save();

        // Signs out every device and stops the account being used again. Soft,
        // so the foreign keys pointing here still resolve.
        $user->delete();
    }
}
