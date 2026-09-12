<?php

namespace Tests\Feature;

use App\Actions\IssueRefund;
use App\Actions\ReconcileSettlements;
use App\Models\Order;
use App\Models\Settlement;
use App\Models\SettlementTransaction;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\SettledTransaction;
use App\Services\Payments\SettlementRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\StubPaymentGateway;
use Tests\TestCase;

/**
 * Settlement reconciliation (FR-M11-06).
 *
 * Almost every test here is about a disagreement between the provider and our
 * own records, because agreement is not what reconciliation is for. The three
 * findings it exists to produce — money with no order, an order with no money,
 * and a settlement we could not read in full — each get their own test, as does
 * the case that would quietly destroy the feature's value: reporting a clean
 * result from an incomplete read.
 */
class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private StubPaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new StubPaymentGateway();
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    private function user(array $attrs = []): User
    {
        return User::forceCreate(array_merge([
            'uuid' => Str::uuid(), 'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'sellers_agent',
            'verification_state' => 'verified',
        ], $attrs));
    }

    private function admin(): User
    {
        return $this->user(['category' => 'seeker', 'is_staff' => true, 'staff_role' => 'admin']);
    }

    private function order(string $state = 'paid', float $amount = 150000, ?int $paidDaysAgo = 6): Order
    {
        return Order::create([
            'uuid' => Str::uuid(), 'user_id' => $this->user()->id,
            'item_type' => 'scan_3d', 'amount' => $amount, 'currency' => 'NGN',
            'state' => $state,
            'paid_at' => $state === 'pending' ? null : now()->subDays($paidDaysAgo),
        ]);
    }

    private function settlement(array $txns, array $overrides = []): void
    {
        $gross = array_sum(array_map(fn (SettledTransaction $t) => $t->amountMinor, $txns));
        $fees  = array_sum(array_map(fn (SettledTransaction $t) => $t->feesMinor, $txns));

        $this->gateway->records = [new SettlementRecord(
            providerId: $overrides['id'] ?? 'stl_1',
            status: $overrides['status'] ?? 'success',
            currency: 'NGN',
            totalMinor: $overrides['total'] ?? $gross,
            feesMinor: $fees,
            deductionsMinor: $overrides['deductions'] ?? 0,
            effectiveMinor: $overrides['effective'] ?? (($overrides['total'] ?? $gross) - $fees - ($overrides['deductions'] ?? 0)),
            settlementDate: now()->subDays(1),
        )];

        $this->gateway->txns[$overrides['id'] ?? 'stl_1'] = $txns;
    }

    private function txn(string $reference, int $amountMinor = 15_000_000, int $feesMinor = 200_000): SettledTransaction
    {
        return new SettledTransaction(
            providerId: 'tx_'.Str::random(6),
            reference: $reference,
            amountMinor: $amountMinor,
            feesMinor: $feesMinor,
            channel: 'card',
            paidAt: now()->subDays(2),
        );
    }

    // ----------------------------------------------------------------- matching

    public function test_a_matched_transaction_is_stamped_on_the_order(): void
    {
        $order = $this->order();
        $this->settlement([$this->txn($order->uuid)]);

        $report = app(ReconcileSettlements::class)->run();

        $order->refresh();

        $this->assertNotNull($order->settlement_id);
        $this->assertNotNull($order->settled_at);
        $this->assertEqualsWithDelta(2000.0, (float) $order->fees_amount, 0.01);
        // Net is what the bank received, which is the figure Finance is asked
        // about and not the figure the customer was charged.
        $this->assertEqualsWithDelta(148000.0, (float) $order->net_amount, 0.01);

        $this->assertSame(1, $report->matched);
        $this->assertSame(0, $report->orphans);
        $this->assertSame('balanced', Settlement::first()->reconciliation_state);
    }

    /**
     * The most valuable row reconciliation can produce: money in the bank that
     * no order on this system accounts for. It is a customer who paid and got
     * nothing, and nothing else in the application would ever notice.
     */
    public function test_a_payment_with_no_order_behind_it_is_flagged(): void
    {
        $this->order();     // one legitimate order, which is not in the payout
        $this->settlement([$this->txn('ref-nobody-has-ever-seen')]);

        $report = app(ReconcileSettlements::class)->run();

        $this->assertSame(1, $report->orphans);
        $this->assertFalse($report->isClean());

        $settlement = Settlement::first();
        $this->assertSame('discrepancy', $settlement->reconciliation_state);
        $this->assertSame(1, $settlement->unmatched_count);
        $this->assertEqualsWithDelta(150000.0, (float) $settlement->unmatched_amount, 0.01);
    }

    /** The mirror image: an order we told someone succeeded, and no money. */
    public function test_a_paid_order_that_never_settles_is_counted(): void
    {
        $this->order();                                     // 6 days ago, never settled
        $this->order(paidDaysAgo: 0);                       // today — not yet due
        $this->settlement([]);

        $report = app(ReconcileSettlements::class)->run();

        $this->assertSame(1, $report->unsettledOrders, 'a payment taken today has not settled yet and is not a finding');
    }

    // ----------------------------------------------------------- partial reads

    /**
     * The failure that would make every other number on the page a lie.
     *
     * If the transaction list came back short, the orders we did not see look
     * exactly like orders that never settled, and the settlement looks like it
     * is missing money. The run has to say it could not read the settlement
     * rather than report confident findings drawn from half a list.
     */
    public function test_a_short_transaction_list_is_a_discrepancy_not_a_clean_run(): void
    {
        $order = $this->order();

        // The provider says the payout was 300,000; we only see one 150,000.
        $this->settlement([$this->txn($order->uuid)], [
            'total'     => 30_000_000,
            'effective' => 30_000_000 - 200_000,
        ]);

        app(ReconcileSettlements::class)->run();

        $settlement = Settlement::first();

        $this->assertSame('discrepancy', $settlement->reconciliation_state);
        $this->assertEqualsWithDelta(150000.0, (float) $settlement->variance, 0.01);
    }

    /**
     * And if the list could not be fetched at all, the settlement must stay
     * unreconciled. Marking it balanced on a list we failed to read would be
     * the worst possible outcome of this whole feature.
     */
    public function test_a_failed_transaction_fetch_leaves_the_settlement_unreconciled(): void
    {
        $order = $this->order();
        $this->settlement([$this->txn($order->uuid)]);
        $this->gateway->failTransactions = true;

        $report = app(ReconcileSettlements::class)->run();

        $this->assertSame('unreconciled', Settlement::first()->reconciliation_state);
        $this->assertNotEmpty($report->problems);
        $this->assertFalse($report->isClean());
        $this->assertNull($order->fresh()->settlement_id);
    }

    public function test_a_failed_settlement_fetch_writes_nothing(): void
    {
        $this->gateway->failSettlements = true;

        $report = app(ReconcileSettlements::class)->run();

        $this->assertSame(0, $report->settlements);
        $this->assertNotEmpty($report->problems);
        $this->assertSame(0, Settlement::count());
    }

    /** A payout still in flight has no final transaction list to match against. */
    public function test_a_pending_settlement_is_recorded_but_not_matched(): void
    {
        $order = $this->order();
        $this->settlement([$this->txn($order->uuid)], ['status' => 'pending']);

        app(ReconcileSettlements::class)->run();

        $this->assertSame('unreconciled', Settlement::first()->reconciliation_state);
        $this->assertNull($order->fresh()->settlement_id);
    }

    /** The provider's own arithmetic not adding up is itself a finding. */
    public function test_a_settlement_whose_parts_do_not_add_up_is_a_discrepancy(): void
    {
        $order = $this->order();

        $this->settlement([$this->txn($order->uuid)], [
            'effective' => 14_900_000,      // 149,000 out of 150,000 gross less 2,000 fees
        ]);

        app(ReconcileSettlements::class)->run();

        $settlement = Settlement::first();

        $this->assertFalse($settlement->arithmeticHolds());
        $this->assertSame('discrepancy', $settlement->reconciliation_state);
    }

    // ------------------------------------------------------------ refund polling

    /**
     * Refunds have webhooks, but one missed delivery strands a refund for good
     * and the customer is the one waiting.
     */
    public function test_reconciliation_chases_refunds_the_webhook_never_confirmed(): void
    {
        $order = $this->order();
        $order->update(['paystack_reference' => 'ref_'.Str::random(8)]);

        $refund = app(IssueRefund::class)->request($order, $this->admin(), 150000, 'Capture cancelled by us.');
        $this->assertSame('submitted', $refund->state);

        $this->gateway->refundStatuses[$refund->provider_refund_id] = 'processed';
        $this->settlement([]);

        $report = app(ReconcileSettlements::class)->run();

        $this->assertSame(1, $report->refundsPolled);
        $this->assertSame(1, $report->refundsResolved);
        $this->assertSame('processed', $refund->fresh()->state);
        $this->assertSame('refunded', $order->fresh()->state);
    }

    // -------------------------------------------------------------- the command

    /**
     * A reconciliation that always exits 0 is a reconciliation nobody reads.
     */
    public function test_the_command_fails_when_something_needs_a_person(): void
    {
        $this->settlement([$this->txn('ref-nobody-has-ever-seen')]);

        $this->artisan('agentpro:reconcile-settlements')
            ->expectsOutputToContain('no order behind it')
            ->assertExitCode(1);
    }

    public function test_the_command_succeeds_on_a_clean_run(): void
    {
        $order = $this->order();
        $this->settlement([$this->txn($order->uuid)]);

        $this->artisan('agentpro:reconcile-settlements')
            ->expectsOutputToContain('Everything reconciles')
            ->assertExitCode(0);
    }

    // ----------------------------------------------------------------- the screen

    public function test_settlements_are_admin_only(): void
    {
        $this->actingAs($this->user(['category' => 'seeker', 'is_staff' => true, 'staff_role' => 'moderator']))
            ->get(route('admin.settlements'))
            ->assertNotFound();

        $this->actingAs($this->admin())->get(route('admin.settlements'))->assertOk();
    }

    public function test_an_admin_can_recover_a_payment_that_settled_but_was_never_confirmed(): void
    {
        // The order exists and is unpaid: the webhook never arrived.
        $order = $this->order('pending');
        $this->settlement([$this->txn($order->uuid)]);

        app(ReconcileSettlements::class)->run();

        $transaction = SettlementTransaction::firstOrFail();

        // The reference resolves, so it is not an orphan — but the order is not
        // marked paid, so the money is still unaccounted for and the settlement
        // must not read as balanced.
        $this->assertFalse($transaction->isOrphan());
        $this->assertTrue($transaction->isUnconfirmed());
        $this->assertSame('discrepancy', Settlement::firstOrFail()->reconciliation_state);

        $this->actingAs($this->admin())
            ->post(route('admin.settlements.confirm', $transaction))
            ->assertRedirect();

        $order->refresh();

        $this->assertSame('paid', $order->state);
        $this->assertNotNull($order->paid_at);

        // Attributed to the operator who pressed it, and marked as a recovery
        // rather than an ordinary sale.
        $this->assertDatabaseHas('audit_events', ['action' => 'order.paid', 'subject_id' => $order->id]);
    }

    /** A row already matched to an order is not a recovery candidate. */
    public function test_confirming_an_already_matched_transaction_is_refused(): void
    {
        $order = $this->order();
        $this->settlement([$this->txn($order->uuid)]);

        app(ReconcileSettlements::class)->run();

        $this->actingAs($this->admin())
            ->post(route('admin.settlements.confirm', SettlementTransaction::firstOrFail()))
            ->assertSessionHasErrors('transaction');
    }

    public function test_the_settlement_detail_screen_puts_unmatched_money_first(): void
    {
        $order = $this->order();
        $this->settlement([$this->txn($order->uuid), $this->txn('ref-nobody-has-ever-seen')]);

        app(ReconcileSettlements::class)->run();

        $this->actingAs($this->admin())
            ->get(route('admin.settlements.show', Settlement::firstOrFail()))
            ->assertOk()
            ->assertSee('No order', false)
            ->assertSee('Confirm this order', false);
    }

    /** Running it twice must not double-count anything. */
    public function test_reconciliation_is_repeatable(): void
    {
        $order = $this->order();
        $this->settlement([$this->txn($order->uuid)]);

        app(ReconcileSettlements::class)->run();
        app(ReconcileSettlements::class)->run();

        $this->assertSame(1, Settlement::count());
        $this->assertSame(1, SettlementTransaction::count());
        $this->assertSame(1, Settlement::first()->matched_count);
    }
}
