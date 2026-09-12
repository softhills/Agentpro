<?php

namespace Tests\Feature;

use App\Actions\ReceivePaymentWebhook;
use App\Actions\RequestScanUpgrade;
use App\Actions\ScheduleScan;
use App\Enums\LifecycleState;
use App\Models\Area;
use App\Models\Order;
use App\Models\Property;
use App\Models\TechnicianSlot;
use App\Models\Unit;
use App\Models\User;
use App\Services\Payments\FakeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 3D capture purchase and scheduling (M4, M11).
 *
 * This is the only revenue path in R1, so the tests concentrate on the ways
 * money and delivery can come apart: charging for a scan that cannot be
 * performed, confirming a payment that did not happen, crediting one twice, and
 * taking money without ever delivering the visit.
 */
class ScanPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private function area(bool $coverage = true, string $slug = 'ikoyi'): Area
    {
        return Area::firstOrCreate(['slug' => $slug], [
            'name' => Str::headline($slug), 'city' => 'Lagos', 'state' => 'Lagos',
            'is_scan_coverage' => $coverage,
        ]);
    }

    private function lister(): User
    {
        return User::forceCreate([
            'uuid' => Str::uuid(), 'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'sellers_agent',
            'verification_state' => 'verified', 'verified_at' => now(),
        ]);
    }

    private function property(?Area $area = null, array $overrides = []): Property
    {
        $area ??= $this->area();

        $property = Property::create(array_merge([
            'uuid' => Str::uuid(),
            'lister_id' => $this->lister()->id,
            'area_id' => $area->id,
            'title' => '3-Bed Apartment, Ikoyi',
            'slug' => 'ikoyi-'.Str::lower(Str::random(6)),
            'listing_type' => 'apartment',
            'intent' => 'rent',
            'build_status' => 'fully_built',
            'address_line' => '12 Bourdillon Road',
            'city' => 'Lagos', 'state' => 'Lagos',
            'lat' => 6.4488, 'lng' => 3.4390,
            'location' => DB::raw("ST_GeomFromText('POINT(3.4390 6.4488)')"),
            'lifecycle_state' => LifecycleState::Published->value,
            'published_at' => now(),
        ], $overrides));

        Unit::create([
            'uuid' => Str::uuid(), 'property_id' => $property->id, 'is_primary' => true,
            'price' => 7500000, 'price_period' => 'year', 'bedrooms' => 3,
        ]);

        return $property->fresh();
    }

    private function slot(Area $area, int $capacity = 1, string $time = '09:00:00'): TechnicianSlot
    {
        return TechnicianSlot::create([
            'area_id' => $area->id,
            'technician_id' => null,
            'slot_date' => now()->addDays(3)->toDateString(),
            'slot_start' => $time,
            'capacity' => $capacity,
            'booked' => 0,
        ]);
    }

    private function webhookFor(Order $order, ?int $amountKoboOverride = null, ?string $eventId = null): array
    {
        $body = json_encode([
            'event' => 'charge.success',
            'data' => [
                'id' => $eventId ?? '5551234',
                'reference' => $order->uuid,
                'status' => 'success',
                'amount' => $amountKoboOverride ?? (int) round((float) $order->amount * 100),
                'currency' => 'NGN',
                'channel' => 'bank_transfer',
            ],
        ], JSON_UNESCAPED_SLASHES);

        return [$body, (new FakeGateway(config('agentpro.paystack.fake_secret')))->sign($body)];
    }

    // ---------------------------------------------------------------- eligibility

    /** FR-M4-02: taking money for a scan that cannot be performed is the worst failure here. */
    public function test_a_property_outside_coverage_cannot_reach_checkout(): void
    {
        $property = $this->property($this->area(coverage: false, slug: 'ajah'));

        $reasons = app(RequestScanUpgrade::class)->ineligibility($property);
        $this->assertNotEmpty($reasons);
        $this->assertStringContainsString('outside our capture coverage', implode(' ', $reasons));

        $this->actingAs($property->lister)
            ->post(route('scan.checkout', $property))
            ->assertSessionHasErrors('scan');

        $this->assertSame(0, Order::count(), 'no order should exist for an ineligible property');
    }

    public function test_an_under_construction_property_is_ineligible(): void
    {
        $property = $this->property(null, ['build_status' => 'under_construction']);

        $this->assertStringContainsString(
            'completed building',
            implode(' ', app(RequestScanUpgrade::class)->ineligibility($property))
        );
    }

    public function test_land_is_ineligible(): void
    {
        $property = $this->property(null, ['listing_type' => 'land']);

        $this->assertStringContainsString(
            'nothing to capture',
            implode(' ', app(RequestScanUpgrade::class)->ineligibility($property))
        );
    }

    // ------------------------------------------------------------------- pricing

    /** FR-M11-03 / SEC-05: the client never influences the amount. */
    public function test_the_price_comes_from_configuration_not_the_request(): void
    {
        config(['agentpro.prices.scan_3d' => 150000]);
        $property = $this->property();

        $this->actingAs($property->lister)
            ->post(route('scan.checkout', $property), ['amount' => 1, 'price' => 1])
            ->assertRedirect();

        $order = Order::firstOrFail();

        $this->assertEqualsWithDelta(150000.0, (float) $order->amount, 0.01);
        $this->assertSame(config('agentpro.prices.version'), $order->price_version);
        $this->assertSame('pending', $order->state);
    }

    // ------------------------------------------------------------------ webhooks

    /** FR-M4-04: the browser coming back is not evidence of payment. */
    public function test_returning_from_the_gateway_does_not_confirm_payment(): void
    {
        $property = $this->property();
        $order = app(RequestScanUpgrade::class)($property, $property->lister);

        $this->actingAs($property->lister)
            ->get(route('scan.return', $order))
            ->assertOk()
            ->assertSee('Confirming your payment');

        $this->assertSame('pending', $order->fresh()->state);
    }

    /** SEC-05 */
    public function test_a_webhook_with_a_bad_signature_is_rejected(): void
    {
        $property = $this->property();
        $order = app(RequestScanUpgrade::class)($property, $property->lister);
        [$body] = $this->webhookFor($order);

        $this->call('POST', route('webhooks.paystack'), [], [], [], [
            'HTTP_X_PAYSTACK_SIGNATURE' => 'not-a-real-signature',
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertSame('pending', $order->fresh()->state);

        // Rejected attempts are still recorded — a run of them is exactly what
        // you want to be able to see afterwards.
        $this->assertDatabaseHas('payment_events', ['signature_valid' => false]);
    }

    public function test_a_signed_webhook_marks_the_order_paid(): void
    {
        $property = $this->property();
        $order = app(RequestScanUpgrade::class)($property, $property->lister);
        [$body, $signature] = $this->webhookFor($order);

        $this->call('POST', route('webhooks.paystack'), [], [], [], [
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $order->refresh();

        $this->assertSame('paid', $order->state);
        $this->assertNotNull($order->paid_at);
        $this->assertSame('bank_transfer', $order->paystack_channel);
        $this->assertDatabaseHas('audit_events', ['action' => 'order.paid', 'subject_id' => $order->id]);
    }

    /** Providers retry. A replay must not credit twice. */
    public function test_a_replayed_webhook_is_a_no_op(): void
    {
        $property = $this->property();
        $order = app(RequestScanUpgrade::class)($property, $property->lister);
        [$body, $signature] = $this->webhookFor($order);

        $headers = ['HTTP_X_PAYSTACK_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'];

        $this->call('POST', route('webhooks.paystack'), [], [], [], $headers, $body);
        $firstPaidAt = $order->fresh()->paid_at;

        $this->call('POST', route('webhooks.paystack'), [], [], [], $headers, $body)->assertOk();

        $this->assertSame(1, \App\Models\PaymentEvent::count(), 'the retry should not create a second event');
        $this->assertEquals($firstPaidAt, $order->fresh()->paid_at, 'paid_at should not move on a retry');
        $this->assertSame(
            1,
            \App\Models\AuditEvent::where('action', 'order.paid')->count(),
            'the order should be credited exactly once'
        );
    }

    /**
     * A verified transaction for the wrong amount does not mark the order paid.
     *
     * The gateway is swapped for one that reports a short payment, because the
     * fake reports the order's own amount and would never reach the comparison
     * — the test would pass without executing the branch it claims to cover.
     */
    public function test_a_short_payment_does_not_mark_the_order_paid(): void
    {
        $property = $this->property();
        $order = app(RequestScanUpgrade::class)($property, $property->lister);

        $this->app->bind(\App\Services\Payments\PaymentGateway::class, fn () => new class extends FakeGateway {
            public function fetchTransaction(string $reference): ?\App\Services\Payments\TransactionStatus
            {
                // Verified, successful — but for 100 naira against a 150,000 order.
                return new \App\Services\Payments\TransactionStatus(
                    reference: $reference,
                    successful: true,
                    amountMinor: 10_000,
                    currency: 'NGN',
                    channel: 'card',
                );
            }
        });

        $changed = app(ReceivePaymentWebhook::class)->handle(
            eventId: 'evt_short_'.Str::random(8),
            eventType: 'charge.success',
            payload: ['data' => ['reference' => $order->uuid]],
            signatureValid: true,
        );

        $this->assertFalse($changed, 'a short payment must not credit the order');
        $this->assertSame('pending', $order->fresh()->state);
        $this->assertNull($order->fresh()->paid_at);

        // The event is still marked processed, so the provider's retries do not
        // hammer a decision that has already been made.
        $this->assertDatabaseHas('payment_events', ['event_type' => 'charge.success']);
    }

    /** A transaction the provider reports as failed is not a payment. */
    public function test_an_unsuccessful_transaction_does_not_mark_the_order_paid(): void
    {
        $property = $this->property();
        $order = app(RequestScanUpgrade::class)($property, $property->lister);

        $this->app->bind(\App\Services\Payments\PaymentGateway::class, fn () => new class extends FakeGateway {
            public function fetchTransaction(string $reference): ?\App\Services\Payments\TransactionStatus
            {
                return new \App\Services\Payments\TransactionStatus(
                    reference: $reference, successful: false,
                    amountMinor: 15_000_000, currency: 'NGN',
                );
            }
        });

        $changed = app(ReceivePaymentWebhook::class)->handle(
            eventId: 'evt_failed_'.Str::random(8),
            eventType: 'charge.success',
            payload: ['data' => ['reference' => $order->uuid]],
            signatureValid: true,
        );

        $this->assertFalse($changed);
        $this->assertSame('pending', $order->fresh()->state);
    }

    // ----------------------------------------------------------------- scheduling

    public function test_an_unpaid_order_cannot_be_scheduled(): void
    {
        $property = $this->property();
        $order = app(RequestScanUpgrade::class)($property, $property->lister);
        $slot = $this->slot($property->area);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ScheduleScan::class)($order, $slot);
    }

    public function test_booking_claims_the_slot_and_creates_the_job(): void
    {
        $property = $this->property();
        $order = $this->paidOrder($property);
        $slot = $this->slot($property->area);

        $job = app(ScheduleScan::class)($order, $slot);

        $this->assertSame('scheduled', $job->state);
        $this->assertSame(1, $slot->fresh()->booked);
        $this->assertDatabaseHas('audit_events', ['action' => 'scan.scheduled']);
    }

    /** Two listers, one remaining slot. The second must be told, not double-booked. */
    public function test_a_full_slot_cannot_be_double_booked(): void
    {
        $area = $this->area();
        $slot = $this->slot($area, capacity: 1);

        $first  = $this->paidOrder($this->property($area));
        $second = $this->paidOrder($this->property($area));

        app(ScheduleScan::class)($first, $slot);

        try {
            app(ScheduleScan::class)($second, $slot);
            $this->fail('the second booking should have been refused');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('taken', implode(' ', $e->errors()['slot']));
        }

        $this->assertSame(1, $slot->fresh()->booked, 'capacity must not be exceeded');
        $this->assertSame(1, \App\Models\ScanJob::count());
    }

    /** FR-M4-07: paid with nothing booked is recoverable, and visible. */
    public function test_a_paid_but_unscheduled_capture_is_surfaced_on_the_dashboard(): void
    {
        $property = $this->property();
        $order = $this->paidOrder($property);

        $this->assertTrue($order->isUnredeemed());

        $this->actingAs($property->lister)
            ->get(route('lister.dashboard'))
            ->assertOk()
            ->assertSee('paid for and waiting', false);
    }

    public function test_scheduling_with_no_slots_tells_the_lister_the_capture_is_held(): void
    {
        $property = $this->property();
        $order = $this->paidOrder($property);

        // No slots seeded for this area at all.
        $this->actingAs($property->lister)
            ->get(route('scan.schedule', $order))
            ->assertOk()
            ->assertSee('paid for and held', false);
    }

    /** SEC-03 */
    public function test_another_user_cannot_see_or_book_someone_elses_order(): void
    {
        $property = $this->property();
        $order = $this->paidOrder($property);

        $this->actingAs($this->lister())
            ->get(route('scan.schedule', $order))
            ->assertNotFound();
    }

    // -------------------------------------------------------------------- capture

    /** FR-M4-09 / FR-M4-10 */
    public function test_attaching_a_capture_publishes_the_tour(): void
    {
        $property = $this->property();
        $order = $this->paidOrder($property);
        $slot = $this->slot($property->area);
        $job = app(ScheduleScan::class)($order, $slot);

        $technician = User::forceCreate([
            'uuid' => Str::uuid(), 'name' => 'Capture Team',
            'email' => Str::lower(Str::random(8)).'@example.test', 'password' => 'x',
            'category' => 'seeker', 'is_staff' => true, 'staff_role' => 'technician',
            'verification_state' => 'verified',
        ]);
        $job->update(['technician_id' => $technician->id]);

        $this->actingAs($technician)
            ->post(route('technician.capture', $job), ['capture_reference' => 'SxQL3iGyvQk'])
            ->assertRedirect(route('technician.assignments'));

        $this->assertSame('live', $job->fresh()->state);

        $tour = $property->media()->where('kind', 'tour_3d')->first();
        $this->assertNotNull($tour, 'the tour should be attached to the listing');
        $this->assertSame('agentpro_technician', $tour->source);
        $this->assertSame('SxQL3iGyvQk', $tour->provider_ref);

        // The tour is a material change, so the listing counts as updated.
        $this->assertNotNull($property->fresh()->content_updated_at);

        // And it is now visible to seekers.
        $this->get(route('property.show', $property))->assertOk()->assertSee('3D tour');
    }

    private function paidOrder(Property $property): Order
    {
        $order = app(RequestScanUpgrade::class)($property, $property->lister);

        app(ReceivePaymentWebhook::class)->handle(
            eventId: 'evt_'.Str::random(12),
            eventType: 'charge.success',
            payload: ['data' => ['reference' => $order->uuid]],
            signatureValid: true,
        );

        return $order->fresh();
    }
}
