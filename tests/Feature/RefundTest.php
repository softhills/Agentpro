<?php

namespace Tests\Feature;

use App\Actions\IssueRefund;
use App\Actions\ReceivePaymentWebhook;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\RefundResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Refunds (FR-M11-05).
 *
 * The behaviour worth protecting is not that a refund can be sent — it is what
 * must not happen: an order reading "refunded" before the money has moved, the
 * same money going back twice, and one compromised account completing a large
 * refund on its own.
 */
class RefundTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $name = 'Finance'): User
    {
        return User::forceCreate([
            'uuid' => Str::uuid(), 'name' => $name,
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'seeker',
            'is_staff' => true, 'staff_role' => 'admin',
            'verification_state' => 'verified',
        ]);
    }

    private function paidOrder(float $amount = 150000): Order
    {
        $user = User::forceCreate([
            'uuid' => Str::uuid(), 'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'sellers_agent',
            'verification_state' => 'verified',
        ]);

        return Order::create([
            'uuid' => Str::uuid(), 'user_id' => $user->id,
            'item_type' => 'scan_3d', 'amount' => $amount, 'currency' => 'NGN',
            'state' => 'paid', 'paid_at' => now()->subDays(2),
            'paystack_reference' => 'ref_'.Str::random(10),
        ]);
    }

    // ----------------------------------------------------------- the lifecycle

    /**
     * The point of the whole redesign: submitting is not the same as paying
     * back, and the order must not claim otherwise.
     */
    public function test_a_sent_refund_does_not_mark_the_order_refunded_yet(): void
    {
        $order = $this->paidOrder();

        $refund = app(IssueRefund::class)->request($order, $this->admin(), 150000, 'Technician could not access the property.');

        $this->assertSame('submitted', $refund->state);
        $this->assertNotNull($refund->submitted_at);
        $this->assertNotNull($refund->provider_refund_id);

        // Still paid. The customer has not had their money back.
        $this->assertSame('paid', $order->fresh()->state);
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->refunded_amount, 0.01);
    }

    public function test_the_order_turns_refunded_only_when_the_provider_confirms(): void
    {
        $order = $this->paidOrder();
        $refund = app(IssueRefund::class)->request($order, $this->admin(), 150000, 'Capture cancelled by us.');

        app(IssueRefund::class)->settle($refund, new RefundResult(
            providerId: $refund->provider_refund_id,
            status: 'processed',
            amountMinor: 15_000_000,
        ));

        $this->assertSame('processed', $refund->fresh()->state);
        $this->assertSame('refunded', $order->fresh()->state);
        $this->assertEqualsWithDelta(150000.0, (float) $order->fresh()->refunded_amount, 0.01);
    }

    public function test_a_part_refund_leaves_the_order_partially_refunded(): void
    {
        $order = $this->paidOrder();
        $refund = app(IssueRefund::class)->request($order, $this->admin(), 50000, 'Half the rooms were not captured.');

        app(IssueRefund::class)->settle($refund, new RefundResult(
            providerId: $refund->provider_refund_id, status: 'processed', amountMinor: 5_000_000,
        ));

        $this->assertSame('partially_refunded', $order->fresh()->state);
        $this->assertEqualsWithDelta(100000.0, $order->fresh()->refundableAmount(), 0.01);
    }

    // ------------------------------------------------------------ double spend

    /**
     * The race this is really about: two operators, one order, neither refund
     * confirmed yet. Counting only *processed* refunds as spent would let both
     * through and the provider would happily pay both.
     */
    public function test_a_refund_still_in_flight_is_already_spoken_for(): void
    {
        $order = $this->paidOrder();
        $issue = app(IssueRefund::class);

        $issue->request($order, $this->admin('First'), 150000, 'Cancelled before the visit.');

        $this->expectException(RuntimeException::class);
        $issue->request($order->fresh(), $this->admin('Second'), 150000, 'Cancelled again, apparently.');
    }

    public function test_a_refund_cannot_exceed_the_order(): void
    {
        $order = $this->paidOrder();

        $this->expectException(RuntimeException::class);
        app(IssueRefund::class)->request($order, $this->admin(), 500000, 'Overshooting on purpose.');
    }

    public function test_an_unpaid_order_cannot_be_refunded(): void
    {
        $order = $this->paidOrder();
        $order->update(['state' => 'pending', 'paid_at' => null]);

        $this->expectException(RuntimeException::class);
        app(IssueRefund::class)->request($order->fresh(), $this->admin(), 1000, 'Nothing was ever paid.');
    }

    // -------------------------------------------------------------- approval

    public function test_a_large_refund_waits_for_a_second_admin(): void
    {
        config(['agentpro.refunds.dual_approval_above' => 200000]);

        $order  = $this->paidOrder(350000);
        $refund = app(IssueRefund::class)->request($order, $this->admin('Asked'), 350000, 'RealSure check could not be completed.');

        $this->assertSame('requested', $refund->state);
        $this->assertNull($refund->provider_refund_id, 'nothing may reach the provider before approval');
    }

    public function test_the_person_who_asked_cannot_approve_their_own_refund(): void
    {
        config(['agentpro.refunds.dual_approval_above' => 200000]);

        $asker  = $this->admin('Asked');
        $order  = $this->paidOrder(350000);
        $refund = app(IssueRefund::class)->request($order, $asker, 350000, 'RealSure check could not be completed.');

        try {
            app(IssueRefund::class)->approve($refund, $asker);
            $this->fail('a refund was self-approved');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('someone other than', $e->getMessage());
        }

        $this->assertSame('requested', $refund->fresh()->state);
    }

    public function test_a_different_admin_can_approve_and_it_is_then_sent(): void
    {
        config(['agentpro.refunds.dual_approval_above' => 200000]);

        $order  = $this->paidOrder(350000);
        $refund = app(IssueRefund::class)->request($order, $this->admin('Asked'), 350000, 'RealSure check could not be completed.');

        $approved = app(IssueRefund::class)->approve($refund, $this->admin('Approved'));

        $this->assertSame('submitted', $approved->state);
        $this->assertNotNull($approved->approved_by);
        $this->assertNotNull($approved->provider_refund_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'refund.approved', 'subject_id' => $refund->id]);
    }

    // ------------------------------------------------------- provider failure

    /**
     * A refund that failed at the provider must stay visible as failed.
     *
     * If it vanished, the operator would simply try again — and if the first
     * call had in fact been accepted before the timeout, the customer gets paid
     * twice.
     */
    public function test_a_provider_failure_leaves_a_failed_record_behind(): void
    {
        $this->app->bind(PaymentGateway::class, fn () => new class extends FakeGateway
        {
            public function refund(\App\Models\Order $order, float $amount, string $reason): RefundResult
            {
                throw new RuntimeException('Insufficient balance on the integration.');
            }
        });

        $order = $this->paidOrder();

        try {
            app(IssueRefund::class)->request($order, $this->admin(), 150000, 'Capture cancelled by us.');
            $this->fail('a failing gateway was reported as success');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Insufficient balance', $e->getMessage());
        }

        $refund = Refund::where('order_id', $order->id)->firstOrFail();

        $this->assertSame('failed', $refund->state);
        $this->assertStringContainsString('Insufficient balance', $refund->failure_reason);
        $this->assertSame('paid', $order->fresh()->state);

        // And the failed attempt does not block a second, deliberate try.
        $this->assertEqualsWithDelta(150000.0, $order->fresh()->refundableAmount(), 0.01);
    }

    // --------------------------------------------------------------- webhooks

    /**
     * The bug that motivated routing webhooks by type at all: a refund outcome
     * used to be handed to payment confirmation, which found no transaction
     * reference and silently discarded it. The refund would sit in "with the
     * provider" forever while the customer had in fact been paid.
     */
    public function test_a_refund_webhook_completes_the_refund(): void
    {
        $order  = $this->paidOrder();
        $refund = app(IssueRefund::class)->request($order, $this->admin(), 150000, 'Capture cancelled by us.');

        $changed = app(ReceivePaymentWebhook::class)->handle(
            eventId: 'evt_refund_'.Str::random(8),
            eventType: 'refund.processed',
            payload: ['event' => 'refund.processed', 'data' => [
                'id' => $refund->provider_refund_id,
                'status' => 'processed',
                'amount' => 15_000_000,
                'currency' => 'NGN',
                'transaction_reference' => $order->paystack_reference,
            ]],
            signatureValid: true,
        );

        $this->assertTrue($changed);
        $this->assertSame('processed', $refund->fresh()->state);
        $this->assertSame('refunded', $order->fresh()->state);
    }

    /** Providers retry. The second delivery must not refund anything again. */
    public function test_a_replayed_refund_webhook_changes_nothing(): void
    {
        $order  = $this->paidOrder();
        $refund = app(IssueRefund::class)->request($order, $this->admin(), 150000, 'Capture cancelled by us.');

        $payload = ['event' => 'refund.processed', 'data' => [
            'id' => $refund->provider_refund_id, 'status' => 'processed',
            'amount' => 15_000_000, 'currency' => 'NGN',
            'transaction_reference' => $order->paystack_reference,
        ]];

        $id = 'evt_refund_dupe';

        $this->assertTrue(app(ReceivePaymentWebhook::class)->handle($id, 'refund.processed', $payload, true));
        $this->assertFalse(app(ReceivePaymentWebhook::class)->handle($id, 'refund.processed', $payload, true));

        $this->assertEqualsWithDelta(150000.0, (float) $order->fresh()->refunded_amount, 0.01);
        $this->assertSame(1, Refund::where('order_id', $order->id)->count());
    }

    public function test_a_failed_refund_webhook_is_recorded_as_failed(): void
    {
        $order  = $this->paidOrder();
        $refund = app(IssueRefund::class)->request($order, $this->admin(), 150000, 'Capture cancelled by us.');

        app(ReceivePaymentWebhook::class)->handle(
            eventId: 'evt_refund_fail_'.Str::random(8),
            eventType: 'refund.failed',
            payload: ['event' => 'refund.failed', 'data' => [
                'id' => $refund->provider_refund_id, 'status' => 'failed',
                'amount' => 15_000_000, 'refund_note' => 'Card account closed.',
            ]],
            signatureValid: true,
        );

        $this->assertSame('failed', $refund->fresh()->state);
        $this->assertSame('Card account closed.', $refund->fresh()->failure_reason);
        // The money never left, so the order is untouched and refundable again.
        $this->assertSame('paid', $order->fresh()->state);
        $this->assertEqualsWithDelta(150000.0, $order->fresh()->refundableAmount(), 0.01);
    }

    /** An unsigned body is not a refund notification, whatever it claims. */
    public function test_an_unsigned_refund_webhook_does_nothing(): void
    {
        $order  = $this->paidOrder();
        $refund = app(IssueRefund::class)->request($order, $this->admin(), 150000, 'Capture cancelled by us.');

        app(ReceivePaymentWebhook::class)->handle(
            eventId: 'evt_forged_'.Str::random(8),
            eventType: 'refund.processed',
            payload: ['data' => ['id' => $refund->provider_refund_id, 'status' => 'processed']],
            signatureValid: false,
        );

        $this->assertSame('submitted', $refund->fresh()->state);
    }
}
