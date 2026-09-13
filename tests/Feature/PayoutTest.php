<?php

namespace Tests\Feature;

use App\Actions\AddPayoutAccount;
use App\Actions\IssuePayout;
use App\Actions\ReceivePaymentWebhook;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Notifications\PayoutAccountChanged;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\TransferResult;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Payouts to listers (FR-M11-07).
 *
 * Everything before this moved money into the business or back along the
 * transaction that brought it in. This is the first movement with a destination
 * somebody chose, which makes it the only place where a compromised account is
 * worth money to an attacker. Almost every test here is about a control rather
 * than a happy path, because the happy path is a single API call and the
 * controls are the feature.
 */
class PayoutTest extends TestCase
{
    use RefreshDatabase;

    private function lister(array $attrs = []): User
    {
        return User::forceCreate(array_merge([
            'uuid' => Str::uuid(), 'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'sellers_agent',
            'phone' => '08030000001',
            'verification_state' => 'verified', 'verified_at' => now(),
        ], $attrs));
    }

    private function admin(string $name = 'Finance'): User
    {
        return $this->lister([
            'name' => $name, 'category' => 'seeker',
            'is_staff' => true, 'staff_role' => 'admin',
        ]);
    }

    /** A lister with money owed and a usable destination. */
    private function fundedLister(float $balance = 100000, bool $payable = true): User
    {
        $lister = $this->lister();

        Ledger::record($lister, 'credit', $balance, 'listing_incentive', 'Launch incentive');

        // The provider float the payout will actually be sent from. Without a
        // paid order the development gateway correctly reports a zero balance
        // and refuses every transfer — which is the balance check working, not
        // a fixture detail to stub away.
        \App\Models\Order::create([
            'uuid' => Str::uuid(), 'user_id' => $lister->id,
            'item_type' => 'scan_3d', 'amount' => max(150000, $balance * 2),
            'currency' => 'NGN', 'state' => 'paid', 'paid_at' => now()->subDays(5),
        ]);

        PayoutAccount::create([
            'user_id'        => $lister->id,
            'bank_code'      => '058',
            'bank_name'      => 'Guaranty Trust Bank',
            'account_number' => '0123456789',
            'account_name'   => 'TUNDE ADEYEMI',
            'resolved_at'    => now(),
            'name_matches_identity' => true,
            'usable_from'    => $payable ? now()->subDay() : now()->addDay(),
            'is_active'      => true,
        ]);

        return $lister->fresh();
    }

    // ----------------------------------------------------------- the ledger

    public function test_a_balance_is_the_sum_of_its_entries(): void
    {
        $lister = $this->lister();

        Ledger::record($lister, 'credit', 50000, 'listing_incentive', 'September');
        Ledger::record($lister, 'credit', 25000, 'referral', 'Referred Ngozi');
        Ledger::record($lister, 'debit', 10000, 'payout', 'Payout requested');

        $this->assertEqualsWithDelta(65000.0, Ledger::balanceFor($lister), 0.01);
    }

    /** Direction carries the sign, so a negative amount is a programming error. */
    public function test_a_negative_entry_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Ledger::record($this->lister(), 'credit', -500, 'correction', 'Wrong way round');
    }

    // -------------------------------------------------------- bank accounts

    /**
     * The name is the bank's, never the form's.
     *
     * A typed name is a claim. The resolve endpoint is the only fact available
     * about who owns an account number, and it is what every screen displays.
     */
    public function test_the_account_name_comes_from_the_bank(): void
    {
        // Stubbed so the answer cannot come from anywhere but the gateway: the
        // lister is called something else entirely, and the saved name is the
        // bank's.
        $this->app->bind(PaymentGateway::class, fn () => new class extends FakeGateway
        {
            public function resolveAccount(string $accountNumber, string $bankCode): ?\App\Services\Payments\ResolvedAccount
            {
                return new \App\Services\Payments\ResolvedAccount(
                    accountNumber: $accountNumber,
                    accountName: 'TUNDE ADEYEMI',
                    bankCode: $bankCode,
                    bankName: 'Guaranty Trust Bank',
                );
            }
        });

        $lister = $this->lister(['name' => 'Somebody Else']);

        $account = app(AddPayoutAccount::class)($lister, '0123456789', '058');

        $this->assertSame('TUNDE ADEYEMI', $account->account_name);
        $this->assertSame('Guaranty Trust Bank', $account->bank_name);
        $this->assertNotNull($account->resolved_at);
    }

    public function test_an_account_the_bank_does_not_recognise_is_not_saved(): void
    {
        $lister = $this->lister();

        try {
            // The development gateway reserves this number as "unknown".
            app(AddPayoutAccount::class)($lister, '0000000000', '058');
            $this->fail('an unresolvable account was accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('could not be found', $e->getMessage());
        }

        $this->assertSame(0, PayoutAccount::count());
    }

    public function test_bank_details_cannot_be_added_before_identity_is_verified(): void
    {
        $lister = $this->lister(['verification_state' => 'pending', 'verified_at' => null]);

        $this->expectException(RuntimeException::class);

        app(AddPayoutAccount::class)($lister, '0123456789', '058');
    }

    /**
     * The cooling-off period, which is the single most effective control
     * against the attack this feature invents: take over a login, change the
     * bank details, withdraw.
     */
    public function test_new_bank_details_are_held_before_anything_can_be_sent(): void
    {
        config(['agentpro.payouts.account_hold_hours' => 24]);

        $lister = $this->lister();
        $this->actingAs($lister);      // the dev gateway resolves to the acting user

        $account = app(AddPayoutAccount::class)($lister, '0123456789', '058');

        $this->assertTrue($account->name_matches_identity, 'the name is not what is being tested here');
        $this->assertFalse($account->isPayable());
        $this->assertTrue($account->usable_from->isFuture());
        $this->assertStringContainsString('security hold', $account->blocker());

        // The hold is the only thing stopping it.
        $this->travel(25)->hours();
        $this->assertTrue($account->fresh()->isPayable());
    }

    /** Even the first account is held: an account that never had bank details
     *  is exactly as attractive to take over as one that did. */
    public function test_the_hold_applies_to_a_first_account_too(): void
    {
        $account = app(AddPayoutAccount::class)($this->lister(), '0123456789', '058');

        $this->assertNotNull($account->usable_from);
        $this->assertFalse($account->isOutOfCooldown());
    }

    /**
     * The warning has to reach the person being stolen from, so it goes to the
     * details already on file.
     */
    public function test_changing_bank_details_warns_the_existing_contact(): void
    {
        Notification::fake();

        $lister = $this->lister();
        app(AddPayoutAccount::class)($lister, '0123456789', '058');

        Notification::assertSentTo($lister, PayoutAccountChanged::class);
    }

    public function test_changing_the_account_retires_the_previous_one(): void
    {
        $lister = $this->lister();

        $first = app(AddPayoutAccount::class)($lister, '0123456789', '058');
        $second = app(AddPayoutAccount::class)($lister, '9876543210', '044');

        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue($second->is_active);
        $this->assertSame($second->id, $lister->fresh()->activePayoutAccount()->id);
    }

    /**
     * A bank name that is not the verified identity is checked, not refused —
     * agents here legitimately receive into a registered business account.
     */
    public function test_a_mismatched_account_name_needs_a_person(): void
    {
        $this->app->bind(PaymentGateway::class, fn () => new class extends FakeGateway
        {
            public function resolveAccount(string $accountNumber, string $bankCode): ?\App\Services\Payments\ResolvedAccount
            {
                return new \App\Services\Payments\ResolvedAccount(
                    accountNumber: $accountNumber,
                    accountName: 'ADEYEMI PROPERTIES LIMITED',
                    bankCode: $bankCode,
                    bankName: 'Guaranty Trust Bank',
                );
            }
        });

        $account = app(AddPayoutAccount::class)($this->lister(['name' => 'Tunde Adeyemi']), '0123456789', '058');

        $this->assertFalse($account->name_matches_identity);

        $this->travel(25)->hours();

        // Out of cooldown, and still not payable — the name is the blocker now.
        $this->assertTrue($account->fresh()->isOutOfCooldown());
        $this->assertFalse($account->fresh()->isPayable());

        $account->update(['approved_by' => $this->admin()->id, 'approved_at' => now()]);
        $this->assertTrue($account->fresh()->isPayable());
    }

    /** Bank records reorder names and drop middle ones; that is not a mismatch. */
    public function test_a_reordered_name_still_counts_as_a_match(): void
    {
        $account = app(AddPayoutAccount::class)($this->lister(['name' => 'Adeyemi Tunde Olusegun']), '0123456789', '058');

        // The development gateway returns the lister's own name upper-cased once
        // the account exists; on first resolve it is a stand-in, so this asserts
        // the comparison directly instead.
        $this->assertTrue(
            (new \ReflectionMethod(AddPayoutAccount::class, 'namesMatch'))
                ->invoke(app(AddPayoutAccount::class), 'TUNDE ADEYEMI', 'Adeyemi Tunde Olusegun')
        );

        $this->assertFalse(
            (new \ReflectionMethod(AddPayoutAccount::class, 'namesMatch'))
                ->invoke(app(AddPayoutAccount::class), 'ADEYEMI PROPERTIES LTD', 'Tunde Adeyemi')
        );

        $this->assertNotNull($account);
    }

    // -------------------------------------------------------- requesting

    /**
     * The double-spend this design exists to prevent: money is taken off the
     * ledger when a payout is *requested*, not when it is sent.
     */
    public function test_a_requested_payout_is_already_spoken_for(): void
    {
        $lister = $this->fundedLister(100000);
        $payouts = app(IssuePayout::class);

        $payouts->request($lister, 100000, $lister);

        $this->assertEqualsWithDelta(0.0, Ledger::balanceFor($lister), 0.01);

        $this->expectException(RuntimeException::class);
        $payouts->request($lister->fresh(), 100000, $lister);
    }

    public function test_a_payout_cannot_exceed_the_balance(): void
    {
        $lister = $this->fundedLister(20000);

        $this->expectException(RuntimeException::class);
        app(IssuePayout::class)->request($lister, 50000, $lister);
    }

    public function test_a_payout_below_the_minimum_is_refused(): void
    {
        config(['agentpro.payouts.minimum' => 5000]);
        $lister = $this->fundedLister(100000);

        $this->expectException(RuntimeException::class);
        app(IssuePayout::class)->request($lister, 100, $lister);
    }

    public function test_a_lister_with_no_bank_details_cannot_request(): void
    {
        $lister = $this->lister();
        Ledger::record($lister, 'credit', 50000, 'listing_incentive', 'Launch');

        $this->expectException(RuntimeException::class);
        app(IssuePayout::class)->request($lister->fresh(), 50000, $lister);
    }

    // ---------------------------------------------------------- approving

    /**
     * Always a second person, with no threshold that skips it — unlike refunds,
     * because a payout can go somewhere the money has never been.
     */
    public function test_nobody_can_approve_their_own_payout(): void
    {
        $lister = $this->fundedLister();
        $payout = app(IssuePayout::class)->request($lister, 50000, $lister);

        try {
            app(IssuePayout::class)->approve($payout, $lister);
            $this->fail('a payout was self-approved');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('someone other than', $e->getMessage());
        }

        $this->assertSame('requested', $payout->fresh()->state);
    }

    public function test_even_a_small_payout_needs_a_second_admin(): void
    {
        $lister = $this->fundedLister();

        // Requested by one admin on the lister's behalf…
        $payout = app(IssuePayout::class)->request($lister, 5000, $this->admin('First'));

        // …and it still sits waiting rather than going straight out.
        $this->assertSame('requested', $payout->state);
        $this->assertNull($payout->provider_transfer_code);
    }

    public function test_a_second_admin_approves_and_it_is_sent(): void
    {
        $lister = $this->fundedLister();
        $payout = app(IssuePayout::class)->request($lister, 50000, $this->admin('First'));

        $sent = app(IssuePayout::class)->approve($payout, $this->admin('Second'));

        $this->assertSame('submitted', $sent->state);
        $this->assertNotNull($sent->provider_transfer_code);
        // Deliberately not "paid": the bank has not confirmed it yet.
        $this->assertNotSame('paid', $sent->state);
        $this->assertDatabaseHas('audit_events', ['action' => 'payout.submitted', 'subject_id' => $payout->id]);
    }

    /** The hold is re-checked at the moment of sending, not trusted from the request. */
    public function test_a_payout_to_an_account_still_in_cooldown_is_refused(): void
    {
        $lister = $this->fundedLister(payable: false);
        $payout = app(IssuePayout::class)->request($lister, 50000, $this->admin('First'));

        $this->expectException(RuntimeException::class);
        app(IssuePayout::class)->approve($payout, $this->admin('Second'));
    }

    /** Bank details changed after the request: the destination is no longer the one approved. */
    public function test_a_payout_is_refused_when_the_account_changed_underneath_it(): void
    {
        $lister = $this->fundedLister();
        $payout = app(IssuePayout::class)->request($lister, 50000, $this->admin('First'));

        $payout->account->update(['is_active' => false]);

        $this->expectException(RuntimeException::class);
        app(IssuePayout::class)->approve($payout->fresh(), $this->admin('Second'));
    }

    public function test_cancelling_puts_the_money_back(): void
    {
        $lister = $this->fundedLister(100000);
        $payout = app(IssuePayout::class)->request($lister, 40000, $lister);

        $this->assertEqualsWithDelta(60000.0, Ledger::balanceFor($lister), 0.01);

        app(IssuePayout::class)->cancel($payout, $this->admin(), 'Lister asked us to hold it.');

        $this->assertSame('cancelled', $payout->fresh()->state);
        $this->assertEqualsWithDelta(100000.0, Ledger::balanceFor($lister), 0.01);
    }

    // ------------------------------------------------------------ outcomes

    public function test_a_transfer_webhook_completes_the_payout(): void
    {
        $lister = $this->fundedLister();
        $payout = app(IssuePayout::class)->request($lister, 50000, $this->admin('First'));
        app(IssuePayout::class)->approve($payout, $this->admin('Second'));

        $changed = app(ReceivePaymentWebhook::class)->handle(
            eventId: 'evt_transfer_'.Str::random(8),
            eventType: 'transfer.success',
            payload: ['event' => 'transfer.success', 'data' => [
                'status' => 'success',
                'reference' => $payout->fresh()->provider_reference,
                'amount' => 5_000_000,
            ]],
            signatureValid: true,
        );

        $this->assertTrue($changed);
        $this->assertSame('paid', $payout->fresh()->state);
        // Money stays off the ledger: it reached the lister.
        $this->assertEqualsWithDelta(50000.0, Ledger::balanceFor($lister), 0.01);
    }

    /**
     * A reversal is not a failure. The transfer left, the bank could not
     * deliver it, and it came back days later — so the money has to go back on
     * a ledger that already spent it.
     */
    public function test_a_reversed_transfer_returns_the_money_to_the_ledger(): void
    {
        $lister = $this->fundedLister(100000);
        $payout = app(IssuePayout::class)->request($lister, 50000, $this->admin('First'));
        app(IssuePayout::class)->approve($payout, $this->admin('Second'));

        $this->assertEqualsWithDelta(50000.0, Ledger::balanceFor($lister), 0.01);

        app(ReceivePaymentWebhook::class)->handle(
            eventId: 'evt_reversed_'.Str::random(8),
            eventType: 'transfer.reversed',
            payload: ['event' => 'transfer.reversed', 'data' => [
                'status' => 'reversed',
                'reference' => $payout->fresh()->provider_reference,
                'reason' => 'Account name mismatch at the receiving bank.',
            ]],
            signatureValid: true,
        );

        $this->assertSame('reversed', $payout->fresh()->state);
        $this->assertEqualsWithDelta(100000.0, Ledger::balanceFor($lister), 0.01);
    }

    public function test_a_failed_transfer_returns_the_money(): void
    {
        $lister = $this->fundedLister(100000);
        $payout = app(IssuePayout::class)->request($lister, 50000, $this->admin('First'));
        app(IssuePayout::class)->approve($payout, $this->admin('Second'));

        app(ReceivePaymentWebhook::class)->handle(
            eventId: 'evt_failed_'.Str::random(8),
            eventType: 'transfer.failed',
            payload: ['event' => 'transfer.failed', 'data' => [
                'status' => 'failed',
                'reference' => $payout->fresh()->provider_reference,
                'reason' => 'Beneficiary account is dormant.',
            ]],
            signatureValid: true,
        );

        $this->assertSame('failed', $payout->fresh()->state);
        $this->assertEqualsWithDelta(100000.0, Ledger::balanceFor($lister), 0.01);
    }

    /** Providers retry. A replay must not credit the ledger twice. */
    public function test_a_replayed_transfer_webhook_changes_nothing(): void
    {
        $lister = $this->fundedLister(100000);
        $payout = app(IssuePayout::class)->request($lister, 50000, $this->admin('First'));
        app(IssuePayout::class)->approve($payout, $this->admin('Second'));

        $payload = ['event' => 'transfer.reversed', 'data' => [
            'status' => 'reversed', 'reference' => $payout->fresh()->provider_reference,
        ]];

        $this->assertTrue(app(ReceivePaymentWebhook::class)->handle('evt_dupe', 'transfer.reversed', $payload, true));
        $this->assertFalse(app(ReceivePaymentWebhook::class)->handle('evt_dupe', 'transfer.reversed', $payload, true));

        $this->assertEqualsWithDelta(100000.0, Ledger::balanceFor($lister), 0.01);
        $this->assertSame(1, LedgerEntry::where('kind', 'payout_returned')->count());
    }

    /**
     * The one case where money deliberately does NOT go back automatically.
     *
     * A transfer call that throws may have reached the provider before it
     * failed, so crediting the balance here could let the same money be paid
     * out twice. It is returned by a person, once the transfer's fate is known.
     */
    public function test_a_submission_failure_holds_the_money_until_a_person_returns_it(): void
    {
        $this->app->bind(PaymentGateway::class, fn () => new class extends FakeGateway
        {
            public function transfer(string $recipientCode, float $amount, string $reference, string $reason): TransferResult
            {
                throw new RuntimeException('Gateway timeout.');
            }
        });

        $lister = $this->fundedLister(100000);
        $payout = app(IssuePayout::class)->request($lister, 50000, $this->admin('First'));

        try {
            app(IssuePayout::class)->approve($payout, $this->admin('Second'));
            $this->fail('a failing transfer was reported as sent');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Gateway timeout', $e->getMessage());
        }

        $this->assertSame('failed', $payout->fresh()->state);
        // Still held — the fate of that call is unknown.
        $this->assertEqualsWithDelta(50000.0, Ledger::balanceFor($lister), 0.01);

        app(IssuePayout::class)->returnToLedger($payout->fresh(), $this->admin('Third'), 'Confirmed with Paystack that nothing went out.');

        $this->assertEqualsWithDelta(100000.0, Ledger::balanceFor($lister), 0.01);
    }

    public function test_money_cannot_be_returned_to_the_ledger_twice(): void
    {
        $this->app->bind(PaymentGateway::class, fn () => new class extends FakeGateway
        {
            public function transfer(string $recipientCode, float $amount, string $reference, string $reason): TransferResult
            {
                throw new RuntimeException('Gateway timeout.');
            }
        });

        $lister = $this->fundedLister(100000);
        $payout = app(IssuePayout::class)->request($lister, 50000, $this->admin('First'));

        try {
            app(IssuePayout::class)->approve($payout, $this->admin('Second'));
        } catch (RuntimeException) {
            // expected
        }

        $admin = $this->admin('Third');
        app(IssuePayout::class)->returnToLedger($payout->fresh(), $admin, 'Confirmed nothing went out.');

        $this->expectException(RuntimeException::class);
        app(IssuePayout::class)->returnToLedger($payout->fresh(), $admin, 'Confirmed nothing went out.');
    }

    // ------------------------------------------------------------- screens

    public function test_the_payout_screen_is_admin_only(): void
    {
        $moderator = $this->lister(['category' => 'seeker', 'is_staff' => true, 'staff_role' => 'moderator']);

        $this->actingAs($moderator)->get(route('admin.payouts'))->assertNotFound();
        $this->actingAs($this->admin())->get(route('admin.payouts'))->assertOk();
    }

    /**
     * Renders the screen with every branch populated at once.
     *
     * The passing access test only ever loads an empty page, so a broken row
     * template — a pending payout, a mismatched account, a failed transfer —
     * would not be caught by it.
     */
    public function test_the_admin_screen_renders_every_kind_of_row(): void
    {
        $lister = $this->fundedLister(100000);
        $requested = app(IssuePayout::class)->request($lister, 30000, $this->admin('First'));

        $sent = app(IssuePayout::class)->request($lister, 20000, $this->admin('First'));
        app(IssuePayout::class)->approve($sent, $this->admin('Second'));

        // An account whose bank name is not the verified identity.
        PayoutAccount::create([
            'user_id' => $this->lister(['name' => 'Ngozi Balogun'])->id,
            'bank_code' => '044', 'bank_name' => 'Access Bank',
            'account_number' => '9876543210', 'account_name' => 'BALOGUN VENTURES LTD',
            'resolved_at' => now(), 'name_matches_identity' => false,
            'usable_from' => now()->subDay(), 'is_active' => true,
        ]);

        $this->actingAs($this->admin('Viewer'))
            ->get(route('admin.payouts'))
            ->assertOk()
            ->assertSee('Waiting for approval', false)
            ->assertSee('Bank names that do not match', false)
            ->assertSee('BALOGUN VENTURES LTD', false)
            ->assertSee('On its way', false)
            ->assertSee('Launch incentive', false)
            ->assertSee(e($requested->user->name), false);
    }

    public function test_a_lister_sees_their_own_statement(): void
    {
        $lister = $this->fundedLister(75000);

        $this->actingAs($lister)
            ->get(route('lister.payouts'))
            ->assertOk()
            ->assertSee('₦75,000', false)
            ->assertSee('Launch incentive', false);
    }

    public function test_a_lister_cannot_approve_a_payout(): void
    {
        $lister = $this->fundedLister();
        $payout = app(IssuePayout::class)->request($lister, 50000, $lister);

        $this->actingAs($lister)
            ->post(route('admin.payouts.approve', $payout))
            ->assertNotFound();

        $this->assertSame('requested', $payout->fresh()->state);
    }

    public function test_crediting_a_lister_is_recorded_with_its_reason(): void
    {
        $lister = $this->lister();

        $this->actingAs($this->admin())
            ->post(route('admin.payouts.credit'), [
                'user_id' => $lister->id,
                'amount'  => 25000,
                'kind'    => 'listing_incentive',
                'memo'    => 'Launch incentive — 5 verified listings in September',
            ])
            ->assertRedirect();

        $this->assertEqualsWithDelta(25000.0, Ledger::balanceFor($lister), 0.01);
        $this->assertDatabaseHas('audit_events', ['action' => 'ledger.credited', 'subject_id' => $lister->id]);
    }

    public function test_a_credit_without_a_reason_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.payouts.credit'), [
                'user_id' => $this->lister()->id,
                'amount'  => 25000,
                'kind'    => 'listing_incentive',
                'memo'    => 'x',
            ])
            ->assertSessionHasErrors('memo');

        $this->assertSame(0, LedgerEntry::count());
    }
}
