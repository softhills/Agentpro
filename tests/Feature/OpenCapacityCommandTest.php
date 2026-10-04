<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\TechnicianSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Capacity across several areas at once.
 *
 * The console opens slots one area per submission, which is right for a
 * technician's next fortnight and wrong for the day a city opens. An area that
 * is open for capture with no slots behind it is not an empty screen: coverage
 * is what makes the upgrade purchasable, so it sells a capture and then tells
 * the lister there are no dates, after they have paid.
 */
class OpenCapacityCommandTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        return User::forceCreate(array_merge([
            'uuid' => Str::uuid(), 'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x', 'category' => 'seeker',
            'verification_state' => 'verified', 'verified_at' => now(),
        ], $attrs));
    }

    private function technician(): User
    {
        return $this->user(['is_staff' => true, 'staff_role' => 'technician']);
    }

    private function area(string $name, string $slug, bool $coverage = true, string $city = 'Lagos'): Area
    {
        return Area::create([
            'name' => $name, 'slug' => $slug, 'city' => $city, 'state' => $city,
            'is_scan_coverage' => $coverage,
        ]);
    }

    /** The situation it exists for: a city opened, nothing bookable in it. */
    public function test_it_fills_the_areas_that_are_open_with_nothing_bookable(): void
    {
        $technician = $this->technician();
        $stocked    = $this->area('Victoria Island', 'victoria-island');
        $empty      = $this->area('Ajah', 'ajah');
        $enugu      = $this->area('Uwani', 'uwani', true, 'Enugu');
        $closed     = $this->area('Port Harcourt GRA', 'ph-gra', false, 'Port Harcourt');

        TechnicianSlot::create([
            'area_id' => $stocked->id, 'technician_id' => $technician->id,
            'slot_date' => now()->addDays(2)->toDateString(), 'slot_start' => '09:00:00',
            'capacity' => 1, 'booked' => 0,
        ]);

        $this->artisan('agentpro:open-capacity', [
            '--technician' => $technician->email,
            '--days' => 7,
            '--force' => true,
        ])->assertExitCode(0);

        // Seven days from tomorrow contain one Sunday, which is skipped: six
        // days at the two default times.
        $this->assertSame(12, $empty->technicianSlots()->count());
        $this->assertSame(12, $enugu->technicianSlots()->count());

        // An area that already has dates is not the problem this solves, and a
        // closed one cannot be booked against at all.
        $this->assertSame(1, $stocked->technicianSlots()->count());
        $this->assertSame(0, $closed->technicianSlots()->count());

        $this->assertDatabaseHas('audit_events', [
            'action' => 'area.slots_added', 'subject_id' => $empty->id,
        ]);
    }

    /**
     * Sundays are not worked, so a range short enough to be nothing else opens
     * nothing — and says so rather than reporting an empty success or, as it
     * did first, failing on a null date while printing the summary.
     */
    public function test_a_range_that_is_nothing_but_sunday_is_refused(): void
    {
        $technician = $this->technician();
        $this->area('Ajah', 'ajah');

        $this->artisan('agentpro:open-capacity', [
            '--technician' => $technician->email,
            '--from' => Carbon::today()->next('Sunday')->toDateString(),
            '--days' => 1,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, TechnicianSlot::count());
    }

    public function test_sundays_are_skipped_inside_a_longer_range(): void
    {
        $technician = $this->technician();
        $area = $this->area('Ajah', 'ajah');

        $this->artisan('agentpro:open-capacity', [
            '--technician' => $technician->email,
            '--from' => Carbon::today()->next('Monday')->toDateString(),
            '--days' => 7,
            '--times' => '09:00',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(6, $area->technicianSlots()->count());

        foreach ($area->technicianSlots as $slot) {
            $this->assertNotSame('Sunday', $slot->slot_date->format('l'));
        }
    }

    /** A deploy script may run it twice; the second run is not a duplicate. */
    public function test_running_it_again_adds_nothing(): void
    {
        $technician = $this->technician();
        $area = $this->area('Ajah', 'ajah');

        $args = ['--technician' => $technician->email, '--days' => 7, '--force' => true];

        $this->artisan('agentpro:open-capacity', $args)->assertExitCode(0);
        $after = $area->technicianSlots()->count();

        /*
         * Named this time, because the default set no longer includes it — it
         * has dates now, which is the whole point. This is also the pass that
         * has to find the slots it wrote a moment ago: looking for them by a
         * date string that the driver stores differently finds nothing, tries
         * to insert, and dies on the unique index halfway through the areas.
         */
        $this->artisan('agentpro:open-capacity', $args + ['--area' => ['ajah']])->assertExitCode(0);

        $this->assertSame($after, $area->technicianSlots()->count());
    }

    /** A city is the unit this is talked about in, and eleven --area flags is
     *  how the twelfth area gets left out. */
    public function test_a_whole_city_can_be_named(): void
    {
        $technician = $this->technician();
        $enugu  = $this->area('Uwani', 'uwani', true, 'Enugu');
        $also   = $this->area('Asata', 'asata', true, 'Enugu');
        $lagos  = $this->area('Ajah', 'ajah');

        $this->artisan('agentpro:open-capacity', [
            '--technician' => $technician->email,
            // Lower case on purpose: an operator types the city, they do not
            // look up how it is capitalised in the table.
            '--city' => ['enugu'],
            '--days' => 2,
            '--times' => '09:00',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(2, $enugu->technicianSlots()->count());
        $this->assertSame(2, $also->technicianSlots()->count());
        $this->assertSame(0, $lagos->technicianSlots()->count());
    }

    public function test_a_city_with_no_areas_is_refused(): void
    {
        $technician = $this->technician();
        $this->area('Ajah', 'ajah');

        $this->artisan('agentpro:open-capacity', [
            '--technician' => $technician->email,
            '--city' => ['Kano'],
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, TechnicianSlot::count());
    }

    /**
     * A slot names the person who turns up at the property. An administrator
     * satisfies isStaff('technician') and is still not that person.
     */
    public function test_it_refuses_an_account_that_is_not_a_technician(): void
    {
        $admin = $this->user(['is_staff' => true, 'staff_role' => 'admin']);
        $this->area('Ajah', 'ajah');

        $this->artisan('agentpro:open-capacity', [
            '--technician' => $admin->email,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, TechnicianSlot::count());
    }

    public function test_it_writes_nothing_on_a_dry_run(): void
    {
        $technician = $this->technician();
        $this->area('Ajah', 'ajah');

        $this->artisan('agentpro:open-capacity', [
            '--technician' => $technician->email,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, TechnicianSlot::count());
        $this->assertDatabaseMissing('audit_events', ['action' => 'area.slots_added']);
    }

    /**
     * Without --force the answer is no, so a script that forgot it opens
     * nothing rather than committing the field team by default.
     */
    public function test_it_opens_nothing_unless_it_is_confirmed(): void
    {
        $technician = $this->technician();
        $this->area('Ajah', 'ajah');

        $this->artisan('agentpro:open-capacity', ['--technician' => $technician->email])
            ->expectsConfirmation('Open them?', 'no')
            ->assertExitCode(0);

        $this->assertSame(0, TechnicianSlot::count());
    }
}
