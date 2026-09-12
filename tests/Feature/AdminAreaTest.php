<?php

namespace Tests\Feature;

use App\Enums\LifecycleState;
use App\Models\Area;
use App\Models\Order;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The admin area (M12, FR-M12-03, FR-M12-04, FR-M12-05).
 *
 * The dashboard's job is to surface the things that need a person, so the tests
 * concentrate on whether those actually surface — a dashboard that stays quiet
 * while money is sitting undelivered is worse than no dashboard.
 */
class AdminAreaTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        return User::forceCreate(array_merge([
            'uuid' => Str::uuid(), 'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'sellers_agent',
            'verification_state' => 'verified', 'verified_at' => now(),
        ], $attrs));
    }

    private function admin(): User
    {
        return $this->user(['category' => 'seeker', 'is_staff' => true, 'staff_role' => 'moderator']);
    }

    private function area(bool $coverage = true): Area
    {
        return Area::firstOrCreate(['slug' => 'ikoyi'], [
            'name' => 'Ikoyi', 'city' => 'Lagos', 'state' => 'Lagos',
            'is_scan_coverage' => $coverage,
        ]);
    }

    private function listing(string $state = 'published', bool $withFees = true): Property
    {
        $property = Property::create([
            'uuid' => Str::uuid(), 'lister_id' => $this->user()->id, 'area_id' => $this->area()->id,
            'title' => '3-Bed Apartment, Ikoyi', 'slug' => 'ikoyi-'.Str::lower(Str::random(6)),
            'listing_type' => 'apartment', 'intent' => 'rent', 'build_status' => 'fully_built',
            'address_line' => '12 Bourdillon Road', 'city' => 'Lagos', 'state' => 'Lagos',
            'lat' => 6.4488, 'lng' => 3.4390,
            'location' => DB::raw("ST_GeomFromText('POINT(3.4390 6.4488)')"),
            'lifecycle_state' => $state,
            'published_at' => $state === 'published' ? now() : null,
            'submitted_at' => in_array($state, ['submitted', 'under_review'], true) ? now()->subHours(9) : null,
        ]);

        $unit = Unit::create([
            'uuid' => Str::uuid(), 'property_id' => $property->id, 'is_primary' => true,
            'price' => 7500000, 'price_period' => 'year', 'bedrooms' => 3,
        ]);

        if ($withFees) {
            $unit->feeLines()->create(['label' => 'Agency fee', 'amount' => 750000, 'calc_type' => 'fixed']);
        }

        return $property->fresh();
    }

    // ------------------------------------------------------------------- access

    public function test_the_admin_area_is_invisible_to_ordinary_accounts(): void
    {
        $user = $this->user();

        foreach (['admin.dashboard', 'admin.listings', 'admin.users', 'admin.orders', 'admin.operations', 'admin.audit'] as $route) {
            $this->actingAs($user)->get(route($route))->assertNotFound();
        }
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_staff_see_every_admin_screen(): void
    {
        $admin = $this->admin();
        $this->listing();

        foreach (['admin.dashboard', 'admin.listings', 'admin.users', 'admin.orders', 'admin.operations', 'admin.audit', 'admin.queue'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
    }

    // ---------------------------------------------------------------- dashboard

    /** FR-M4-07: money taken with nothing delivered must not sit quietly. */
    public function test_the_dashboard_flags_a_paid_capture_with_no_booking(): void
    {
        $admin = $this->admin();
        $property = $this->listing();

        Order::create([
            'uuid' => Str::uuid(), 'user_id' => $property->lister_id, 'property_id' => $property->id,
            'item_type' => 'scan_3d', 'amount' => 150000, 'currency' => 'NGN',
            'state' => 'paid', 'paid_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('money taken, nothing delivered', false);
    }

    /** FR-M7-01 blocks this at submission, so any occurrence is a bug report. */
    public function test_the_dashboard_flags_a_live_listing_with_no_cost_breakdown(): void
    {
        $this->listing('published', withFees: false);

        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('no cost breakdown', false);
    }

    public function test_the_dashboard_flags_sla_breaches(): void
    {
        $this->listing('submitted');

        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('past the', false)
            ->assertSee('review SLA', false);
    }

    /** A quiet dashboard should say so rather than looking broken. */
    public function test_a_clean_dashboard_says_nothing_is_waiting(): void
    {
        $this->listing();   // published, fees complete, nothing pending

        // Capacity has to exist, or the dashboard correctly reports that the
        // only paid feature cannot be booked — which is not a clean state.
        DB::table('technician_slots')->insert([
            'area_id' => $this->area()->id,
            'technician_id' => null,
            'slot_date' => now()->addDays(2)->toDateString(),
            'slot_start' => '09:00:00',
            'capacity' => 1, 'booked' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Nothing is waiting', false);
    }

    // -------------------------------------------------------------------- people

    public function test_an_admin_can_set_a_verification_state_by_hand(): void
    {
        $lister = $this->user(['verification_state' => 'pending']);

        $this->actingAs($this->admin())
            ->put(route('admin.users.verify', $lister), [
                'verification_state' => 'verified',
                'note' => 'Documents checked by hand.',
            ])
            ->assertRedirect();

        $this->assertTrue($lister->fresh()->isVerified());

        // Recorded under its own action, distinct from a vendor result.
        $this->assertDatabaseHas('audit_events', [
            'action' => 'user.verification_set_manually',
            'subject_id' => $lister->id,
        ]);
    }

    // -------------------------------------------------------------------- orders

    /** FR-M11-05. The record is the thing; the money moves in Paystack. */
    public function test_a_refund_is_recorded_against_the_order(): void
    {
        $property = $this->listing();
        $order = Order::create([
            'uuid' => Str::uuid(), 'user_id' => $property->lister_id, 'property_id' => $property->id,
            'item_type' => 'scan_3d', 'amount' => 150000, 'currency' => 'NGN',
            'state' => 'paid', 'paid_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.orders.refund', $order), ['amount' => 150000, 'reason' => 'Technician could not access the property.'])
            ->assertRedirect();

        $order->refresh();

        $this->assertSame('refunded', $order->state);
        $this->assertEqualsWithDelta(150000.0, (float) $order->refunded_amount, 0.01);
        $this->assertDatabaseHas('audit_events', ['action' => 'order.refunded', 'subject_id' => $order->id]);
    }

    public function test_a_refund_cannot_exceed_what_is_outstanding(): void
    {
        $property = $this->listing();
        $order = Order::create([
            'uuid' => Str::uuid(), 'user_id' => $property->lister_id, 'property_id' => $property->id,
            'item_type' => 'scan_3d', 'amount' => 150000, 'currency' => 'NGN',
            'state' => 'paid', 'paid_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.orders.refund', $order), ['amount' => 500000, 'reason' => 'Overshooting on purpose.'])
            ->assertSessionHasErrors('amount');

        $this->assertSame('paid', $order->fresh()->state);
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->refunded_amount, 0.01);
    }

    // ---------------------------------------------------------------- operations

    /** FR-M4-02: coverage is data, so opening an area needs no deployment. */
    public function test_coverage_can_be_toggled_and_is_audited(): void
    {
        $area = $this->area(coverage: false);

        $this->actingAs($this->admin())
            ->put(route('admin.areas.coverage', $area))
            ->assertRedirect();

        $this->assertTrue($area->fresh()->is_scan_coverage);
        $this->assertDatabaseHas('audit_events', ['action' => 'area.coverage_changed', 'subject_id' => $area->id]);
    }

    public function test_capacity_can_be_opened_and_skips_sundays(): void
    {
        $area = $this->area();
        $technician = $this->user(['category' => 'seeker', 'is_staff' => true, 'staff_role' => 'technician']);

        $this->actingAs($this->admin())
            ->post(route('admin.areas.slots', $area), [
                'technician_id' => $technician->id,
                'from'  => now()->next('Monday')->toDateString(),
                'days'  => 7,
                'times' => ['09:00', '13:00'],
            ])
            ->assertRedirect();

        $slots = DB::table('technician_slots')->where('area_id', $area->id)->get();

        // Seven calendar days from a Monday contains one Sunday, which is
        // skipped: six working days at two slots each.
        $this->assertCount(12, $slots);

        foreach ($slots as $slot) {
            $this->assertNotSame('Sunday', \Illuminate\Support\Carbon::parse($slot->slot_date)->format('l'));
        }
    }

    // -------------------------------------------------------------------- audit

    /** SEC-12: there is deliberately no write path into the log from here. */
    public function test_the_audit_log_is_read_only(): void
    {
        $this->listing();

        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'admin/audit'))
            ->flatMap(fn ($r) => $r->methods());

        $this->assertEmpty(
            $routes->intersect(['POST', 'PUT', 'PATCH', 'DELETE']),
            'the audit log must expose no write routes'
        );
    }
}
