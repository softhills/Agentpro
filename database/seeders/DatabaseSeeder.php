<?php

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\Area;
use App\Models\MediaAsset;
use App\Models\Order;
use App\Models\Property;
use App\Models\Refund;
use App\Models\RealsureRecord;
use App\Models\ScanJob;
use App\Models\TitleClaim;
use App\Models\Unit;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Development inventory.
 *
 * Seeded against the ten 3D coverage areas from the spec, because risk R9 says
 * the map-first search looks broken on an empty city — a developer opening this
 * for the first time should see the product working, not an empty viewport.
 *
 * Figures are plausible for Lagos and Abuja in 2026 but are illustrative, not
 * real inventory.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAreas();
        $this->seedAmenities();

        $agent = User::create([
            'uuid' => Str::uuid(),
            'name' => 'Tunde Adeyemi',
            'email' => 'tunde@example.test',
            'password' => Hash::make('password'),
            'category' => 'sellers_agent',
            'phone' => '+2348030000001',
            'verification_state' => 'verified',
            'verified_at' => now()->subMonths(7),
            'verification_vendor' => 'smileid',
        ]);

        $developer = User::create([
            'uuid' => Str::uuid(),
            'name' => 'Ngozi Balogun',
            'email' => 'ngozi@example.test',
            'password' => Hash::make('password'),
            'category' => 'developer',
            'phone' => '+2348030000002',
            'verification_state' => 'verified',
            'verified_at' => now()->subMonths(3),
            'verification_vendor' => 'verifyme',
        ]);

        $officer = User::create([
            'uuid' => Str::uuid(),
            'name' => 'RealSure Desk',
            'email' => 'realsure@example.test',
            'password' => Hash::make('password'),
            'category' => 'seeker',
            'is_staff' => true,
            // Full admin, not just the RealSure desk: this is the account a
            // developer signs in with to see the whole console. 'admin'
            // satisfies every narrower staff check, so the RealSure work it
            // is attributed to below still fits.
            'staff_role' => 'admin',
            'verification_state' => 'verified',
        ]);

        // FR-M11-05 needs two different admins to be demonstrable at all: a
        // large refund cannot be approved by the person who asked for it.
        $finance = User::create([
            'uuid' => Str::uuid(),
            'name' => 'Finance Desk',
            'email' => 'finance@example.test',
            'password' => Hash::make('password'),
            'category' => 'seeker',
            'is_staff' => true,
            'staff_role' => 'admin',
            'verification_state' => 'verified',
        ]);

        /*
         * A real RealSure Officer, with only that role (FR-M6-01).
         *
         * `realsure@example.test` above is an admin, so it can open the console
         * but sees the whole sidebar and proves nothing about the role itself.
         * This account is how the interesting half is demonstrable: granting a
         * trust badge without being a moderator, and a console that offers only
         * the one section its holder can actually open.
         */
        User::create([
            'uuid' => Str::uuid(),
            'name' => 'Adaeze Okonkwo',
            'email' => 'officer@example.test',
            'password' => Hash::make('password'),
            'category' => 'seeker',
            'is_staff' => true,
            'staff_role' => 'realsure_officer',
            'verification_state' => 'verified',
        ]);

        $technician = User::create([
            'uuid' => Str::uuid(),
            'name' => 'Capture Team — Lagos',
            'email' => 'technician@example.test',
            'password' => Hash::make('password'),
            'category' => 'seeker',
            'is_staff' => true,
            'staff_role' => 'technician',
            'verification_state' => 'verified',
        ]);

        $this->seedProperties($agent, $developer, $officer);
        $this->seedTechnicianSlots($technician);
        $this->seedCommerce($agent, $officer, $technician);
        $this->seedLedger($agent, $developer, $officer);
    }

    /**
     * Supply-side incentives (FR-M11-07, objective O4).
     *
     * Deliberately no bank details and no payouts: an account has to be added
     * by the lister and then sits through a security hold, and seeding one
     * would skip the control the whole feature is built around.
     */
    private function seedLedger(User $agent, User $developer, User $admin): void
    {
        \App\Support\Ledger::record($agent, 'credit', 75000, 'listing_incentive',
            'Launch incentive - 5 verified listings in August', null, $admin->id);

        \App\Support\Ledger::record($agent, 'credit', 25000, 'referral',
            'Referred Ngozi Balogun', null, $admin->id);

        \App\Support\Ledger::record($developer, 'credit', 50000, 'listing_incentive',
            'Launch incentive - Maitama and Wuse II', null, $admin->id);
    }

    /**
     * Orders, refunds and the settlements behind them (M11).
     *
     * Enough of a trading history that the money screens have something real to
     * show. Deliberately does NOT seed a reconciliation failure: the development
     * gateway derives settlements from these very orders, so anything it
     * reported as unaccounted for would be a fiction planted here rather than a
     * disagreement between two systems. The failure paths are covered in
     * ReconciliationTest, where the provider can be made to disagree.
     */
    private function seedCommerce(User $agent, User $admin, User $technician): void
    {
        $properties = Property::where('lister_id', $agent->id)->orderBy('id')->take(4)->get();

        if ($properties->count() < 4) {
            return;
        }

        $order = fn (Property $property, string $item, float $amount, int $daysAgo) => Order::create([
            'uuid' => Str::uuid(),
            'user_id' => $property->lister_id,
            'property_id' => $property->id,
            'item_type' => $item,
            'amount' => $amount,
            'currency' => 'NGN',
            'price_version' => config('agentpro.prices.version'),
            'state' => 'paid',
            'paystack_reference' => null,
            'paystack_channel' => 'bank_transfer',
            'paid_at' => now()->subDays($daysAgo)->setTime(11, 14),
        ]);

        // Delivered: paid, captured, live.
        $delivered = $order($properties[0], 'scan_3d', 150000, 9);
        ScanJob::create([
            'uuid' => Str::uuid(),
            'property_id' => $properties[0]->id,
            'order_id' => $delivered->id,
            'area_id' => $properties[0]->area_id,
            'technician_id' => $technician->id,
            'state' => 'live',
            'scheduled_for' => now()->subDays(6)->setTime(10, 0),
            'attended_at' => now()->subDays(6)->setTime(10, 12),
            'capture_reference' => 'SxQL3iGyvQk',
        ]);

        // FR-M4-07: paid, nothing booked. The dashboard is supposed to shout.
        $order($properties[1], 'scan_3d', 150000, 4);

        // Part-refunded after the capture came back short.
        $short = $order($properties[2], 'scan_3d', 150000, 12);
        $this->refund($short, $admin, 50000, 'Two bedrooms were missed on the capture.', 'processed');

        // Over the dual-approval ceiling, so it sits waiting for finance@ to
        // agree — the state the approval screen exists for.
        $big = $order($properties[3], 'realsure', 350000, 3);
        $this->refund($big, $admin, 350000, 'Title search could not be completed at the registry.', 'requested');

        // Someone part-way through checkout who never paid.
        Order::create([
            'uuid' => Str::uuid(),
            'user_id' => $agent->id,
            'property_id' => $properties[1]->id,
            'item_type' => 'scan_3d',
            'amount' => 150000,
            'currency' => 'NGN',
            'price_version' => config('agentpro.prices.version'),
            'state' => 'pending',
        ]);
    }

    private function refund(Order $order, User $admin, float $amount, string $reason, string $state): void
    {
        $submitted = $state === 'requested' ? null : $order->paid_at->copy()->addDays(2);

        Refund::create([
            'uuid' => Str::uuid(),
            'order_id' => $order->id,
            'amount' => $amount,
            'currency' => 'NGN',
            'reason' => $reason,
            'state' => $state,
            'requested_by' => $admin->id,
            'approved_by' => $state === 'requested' ? null : $admin->id,
            'approved_at' => $submitted,
            'submitted_at' => $submitted,
            'processed_at' => $state === 'processed' ? $submitted->copy()->addDays(2) : null,
            'provider' => 'fake',
            'provider_refund_id' => $state === 'requested' ? null : 'fake_rf_'.Str::random(16),
            'provider_status' => $state === 'requested' ? null : $state,
        ]);

        $order->syncRefundState();
    }

    /**
     * Bookable capacity for the next fortnight (FR-M4-05).
     *
     * Deliberately thin — two visits a day per area, which is roughly what one
     * technician can actually do in Lagos traffic. Risk R2 is that a calendar
     * offering more than the field team can service turns the only paid feature
     * in R1 into a queue of apologies.
     */
    private function seedTechnicianSlots(User $technician): void
    {
        $areas = Area::scanCoverage()->get();

        foreach ($areas as $area) {
            for ($day = 1; $day <= 14; $day++) {
                $date = now()->addDays($day);

                if ($date->isSunday()) {
                    continue;
                }

                foreach (['09:00:00', '13:00:00'] as $start) {
                    \App\Models\TechnicianSlot::create([
                        'area_id'       => $area->id,
                        'technician_id' => $technician->id,
                        'slot_date'     => $date->toDateString(),
                        'slot_start'    => $start,
                        'capacity'      => 1,
                        'booked'        => 0,
                    ]);
                }
            }
        }
    }

    private function seedAreas(): void
    {
        // The ten coverage areas named in the spec, plus nearby areas that are
        // deliberately NOT covered — the 3D upgrade must be seen to refuse them.
        $areas = [
            ['Victoria Island', 'Lagos', 'Lagos', true, 6.4281, 3.4219],
            ['Ikoyi', 'Lagos', 'Lagos', true, 6.4488, 3.4390],
            ['Banana Island', 'Lagos', 'Lagos', true, 6.4419, 3.4470],
            ['Lekki Phase 1', 'Lagos', 'Lagos', true, 6.4478, 3.4723],
            ['Yaba', 'Lagos', 'Lagos', true, 6.5095, 3.3711],
            ['Maitama', 'Abuja', 'FCT', true, 9.0854, 7.4915],
            ['Asokoro', 'Abuja', 'FCT', true, 9.0392, 7.5250],
            ['Jabi', 'Abuja', 'FCT', true, 9.0640, 7.4200],
            ['Wuse II', 'Abuja', 'FCT', true, 9.0765, 7.4620],
            ['Central Business District', 'Abuja', 'FCT', true, 9.0400, 7.4900],
            // Outside coverage
            ['Ajah', 'Lagos', 'Lagos', false, 6.4698, 3.5852],
            ['Gbagada', 'Lagos', 'Lagos', false, 6.5568, 3.3903],
            ['Gwarinpa', 'Abuja', 'FCT', false, 9.1090, 7.4030],
        ];

        foreach ($areas as [$name, $city, $state, $coverage, $lat, $lng]) {
            Area::create([
                'name' => $name,
                'slug' => Str::slug($name),
                'city' => $city,
                'state' => $state,
                'is_scan_coverage' => $coverage,
                'centroid_lat' => $lat,
                'centroid_lng' => $lng,
            ]);
        }
    }

    private function seedAmenities(): void
    {
        // FR-M5-09. This is the set that actually drives decisions here; a US
        // amenity list (pets, in-unit laundry) would filter on nothing.
        $amenities = [
            ['24-hour power', 'power'],
            ['Inverter + solar', 'power'],
            ['Generator included', 'power'],
            ['Treated borehole', 'water'],
            ['Water treatment plant', 'water'],
            ['Gated estate', 'security'],
            ['Estate security', 'security'],
            ['CCTV', 'security'],
            ['BQ included', 'space'],
            ['Parking space', 'space'],
            ['Elevator', 'space'],
            ['Swimming pool', 'leisure'],
            ['Gym', 'leisure'],
            ['POP ceiling', 'finish'],
            ['Fitted kitchen', 'finish'],
            ['All rooms ensuite', 'finish'],
            ['Serviced (service charge)', 'finish'],
        ];

        foreach ($amenities as $i => [$name, $group]) {
            Amenity::create([
                'name' => $name,
                'slug' => Str::slug($name),
                'group' => $group,
                'sort_order' => $i,
            ]);
        }
    }

    private function seedProperties(User $agent, User $developer, User $officer): void
    {
        $rows = [
            [
                'title' => '3-Bed Apartment, Ikate',
                'area' => 'lekki-phase-1', 'address' => 'Off Ikate Elegushi Road',
                'lat' => 6.4441, 'lng' => 3.4795, 'w3w' => '///plant.chief.maker',
                'type' => 'apartment', 'intent' => 'rent', 'finish' => 'furnished',
                'lister' => $agent, 'realsure' => true, 'days_ago' => 2,
                'description' => 'Newly built three-bedroom apartment in a serviced block off Ikate Elegushi, with 24-hour power, treated borehole water and secured parking for two vehicles. All rooms ensuite with a guest toilet. Service charge covers estate security, waste, water treatment and generator diesel.',
                'units' => [
                    ['label' => null, 'price' => 7500000, 'period' => 'year', 'beds' => 3, 'baths' => 3, 'toilets' => 4, 'sqm' => 142],
                ],
                'fees' => [
                    ['Agency fee', 750000, 'percentage', 10, false, 'Agent'],
                    ['Legal fee', 375000, 'percentage', 5, false, 'Solicitor'],
                    ['Service charge', 1200000, 'fixed', null, false, 'Estate management'],
                    ['Caution deposit', 750000, 'fixed', null, true, 'Landlord'],
                ],
                'titles' => [['certificate_of_occupancy', 'available'], ['deed_of_assignment', 'available'], ['governors_consent', 'in_progress'], ['building_plan_approval', 'available']],
                'amenities' => ['24-hour-power', 'inverter-solar', 'treated-borehole', 'gated-estate', 'estate-security', 'parking-space', 'pop-ceiling', 'fitted-kitchen', 'bq-included', 'all-rooms-ensuite'],
                'media' => ['photo' => 24, 'video' => 134, 'tour_3d' => true, 'pano_360' => true, 'floor_plan' => true],
            ],
            [
                'title' => '2-Bed Serviced Flat, Admiralty',
                'area' => 'lekki-phase-1', 'address' => 'Admiralty Way',
                'lat' => 6.4502, 'lng' => 3.4692, 'w3w' => '///rally.tokens.jumped',
                'type' => 'apartment', 'intent' => 'rent', 'finish' => 'furnished',
                'lister' => $developer, 'realsure' => false, 'days_ago' => 5,
                'description' => 'Serviced two-bedroom flats in a block of eight, with lift access, standby generator and estate security. Three units remain available.',
                'units' => [
                    ['label' => 'Flat 2A', 'price' => 5200000, 'period' => 'year', 'beds' => 2, 'baths' => 2, 'toilets' => 3, 'sqm' => 96, 'primary' => true],
                    ['label' => 'Flat 3B', 'price' => 5400000, 'period' => 'year', 'beds' => 2, 'baths' => 2, 'toilets' => 3, 'sqm' => 98],
                    ['label' => 'Flat 4A', 'price' => 5600000, 'period' => 'year', 'beds' => 2, 'baths' => 2, 'toilets' => 3, 'sqm' => 101],
                    ['label' => 'Flat 1B', 'price' => 5200000, 'period' => 'year', 'beds' => 2, 'baths' => 2, 'toilets' => 3, 'sqm' => 96, 'status' => 'taken'],
                    ['label' => 'Flat 1A', 'price' => 5200000, 'period' => 'year', 'beds' => 2, 'baths' => 2, 'toilets' => 3, 'sqm' => 96, 'status' => 'taken'],
                ],
                'fees' => [
                    ['Agency fee', 520000, 'percentage', 10, false, 'Agent'],
                    ['Legal fee', 260000, 'percentage', 5, false, 'Solicitor'],
                    ['Service charge', 900000, 'fixed', null, false, 'Estate management'],
                    ['Caution deposit', 520000, 'fixed', null, true, 'Landlord'],
                ],
                'titles' => [['certificate_of_occupancy', 'available'], ['deed_of_assignment', 'available']],
                'amenities' => ['24-hour-power', 'generator-included', 'elevator', 'estate-security', 'parking-space', 'serviced-service-charge', 'fitted-kitchen'],
                'media' => ['photo' => 18, 'video' => 96, 'tour_3d' => true],
            ],
            [
                'title' => '4-Bed Semi-Detached, Fola Osibo',
                'area' => 'lekki-phase-1', 'address' => 'Off Fola Osibo Street',
                'lat' => 6.4396, 'lng' => 3.4761, 'w3w' => '///sleeps.cheeks.formed',
                'type' => 'house', 'intent' => 'rent', 'finish' => 'unfurnished',
                'lister' => $agent, 'realsure' => true, 'days_ago' => 11,
                'description' => 'Four-bedroom semi-detached house with a detached BQ, on a quiet street inside the Phase 1 scheme. Borehole with treatment plant, inverter backup and parking for three cars.',
                'units' => [
                    ['label' => null, 'price' => 11000000, 'period' => 'year', 'beds' => 4, 'baths' => 4, 'toilets' => 5, 'sqm' => 245],
                ],
                'fees' => [
                    ['Agency fee', 1100000, 'percentage', 10, false, 'Agent'],
                    ['Legal fee', 550000, 'percentage', 5, false, 'Solicitor'],
                    ['Caution deposit', 1100000, 'fixed', null, true, 'Landlord'],
                ],
                'titles' => [['certificate_of_occupancy', 'available'], ['governors_consent', 'available']],
                'amenities' => ['inverter-solar', 'treated-borehole', 'water-treatment-plant', 'gated-estate', 'bq-included', 'parking-space', 'pop-ceiling'],
                'media' => ['photo' => 21, 'tour_3d' => true, 'floor_plan' => true],
            ],
            [
                'title' => '1-Bed Studio Apartment',
                'area' => 'lekki-phase-1', 'address' => 'Ikate Elegushi',
                'lat' => 6.4425, 'lng' => 3.4842, 'w3w' => '///widen.public.mimic',
                'type' => 'apartment', 'intent' => 'rent', 'finish' => 'unfurnished',
                'lister' => $developer, 'realsure' => false, 'days_ago' => 20,
                'description' => 'Compact studio with a fitted kitchenette, suited to a single professional. Estate has 20-hour power and gated security.',
                'units' => [
                    ['label' => null, 'price' => 3800000, 'period' => 'year', 'beds' => 1, 'baths' => 1, 'toilets' => 2, 'sqm' => 58],
                ],
                'fees' => [
                    ['Agency fee', 380000, 'percentage', 10, false, 'Agent'],
                    ['Legal fee', 190000, 'percentage', 5, false, 'Solicitor'],
                    ['Caution deposit', 380000, 'fixed', null, true, 'Landlord'],
                ],
                'titles' => [['deed_of_assignment', 'available'], ['survey_plan', 'available']],
                'amenities' => ['gated-estate', 'estate-security', 'fitted-kitchen', 'parking-space'],
                'media' => ['photo' => 12, 'floor_plan' => true],
            ],
            [
                'title' => '4-Bed Terrace Duplex + BQ',
                'area' => 'wuse-ii', 'address' => 'Off Aminu Kano Crescent',
                'lat' => 9.0781, 'lng' => 7.4603, 'w3w' => '///pouch.lofty.grain',
                'type' => 'house', 'intent' => 'sale', 'finish' => 'core',
                'lister' => $developer, 'realsure' => true, 'days_ago' => 0,
                'description' => 'Twelve-unit terrace development in Wuse II, six units remaining. Core finishing with POP, fitted kitchens and a detached BQ per unit. Payment plan available over eighteen months.',
                'units' => [
                    ['label' => 'Unit 4', 'price' => 185000000, 'period' => 'once', 'beds' => 4, 'baths' => 4, 'toilets' => 5, 'sqm' => 310, 'primary' => true, 'was' => 199000000],
                    ['label' => 'Unit 5', 'price' => 185000000, 'period' => 'once', 'beds' => 4, 'baths' => 4, 'toilets' => 5, 'sqm' => 310],
                    ['label' => 'Unit 6', 'price' => 192000000, 'period' => 'once', 'beds' => 4, 'baths' => 4, 'toilets' => 5, 'sqm' => 322],
                    ['label' => 'Unit 7', 'price' => 192000000, 'period' => 'once', 'beds' => 4, 'baths' => 4, 'toilets' => 5, 'sqm' => 322],
                    ['label' => 'Unit 8', 'price' => 185000000, 'period' => 'once', 'beds' => 4, 'baths' => 4, 'toilets' => 5, 'sqm' => 310],
                    ['label' => 'Unit 9', 'price' => 185000000, 'period' => 'once', 'beds' => 4, 'baths' => 4, 'toilets' => 5, 'sqm' => 310],
                    ['label' => 'Unit 1', 'price' => 185000000, 'period' => 'once', 'beds' => 4, 'baths' => 4, 'toilets' => 5, 'sqm' => 310, 'status' => 'taken'],
                    ['label' => 'Unit 2', 'price' => 185000000, 'period' => 'once', 'beds' => 4, 'baths' => 4, 'toilets' => 5, 'sqm' => 310, 'status' => 'taken'],
                ],
                'fees' => [
                    ['Agency fee', 9250000, 'percentage', 5, false, 'Agent'],
                    ['Legal fee', 3700000, 'percentage', 2, false, 'Solicitor'],
                    ["Governor's consent / perfection", 14800000, 'percentage', 8, false, 'FCTA'],
                ],
                'titles' => [['certificate_of_occupancy', 'available'], ['building_plan_approval', 'available'], ['fcda', 'available'], ['deed_of_assignment', 'in_progress']],
                'amenities' => ['24-hour-power', 'generator-included', 'gated-estate', 'estate-security', 'cctv', 'bq-included', 'parking-space', 'pop-ceiling', 'fitted-kitchen'],
                'media' => ['photo' => 30, 'video' => 158, 'tour_3d' => true, 'drone' => true, 'floor_plan' => true],
            ],
            [
                'title' => '1,000 m² Residential Plot',
                'area' => 'jabi', 'address' => 'Jabi District, Cadastral Zone B06',
                'lat' => 9.0668, 'lng' => 7.4186, 'w3w' => '///cheer.risen.lasted',
                'type' => 'land', 'intent' => 'sale', 'finish' => null,
                'lister' => $agent, 'realsure' => false, 'days_ago' => 8,
                'description' => 'Fenced corner plot of 1,000 square metres in Jabi, with Certificate of Occupancy and a registered survey plan. Drone survey and perimeter walkthrough available.',
                'units' => [
                    ['label' => null, 'price' => 95000000, 'period' => 'once', 'beds' => null, 'baths' => null, 'toilets' => null, 'sqm' => 1000],
                ],
                'fees' => [
                    ['Agency fee', 4750000, 'percentage', 5, false, 'Agent'],
                    ['Legal fee', 1900000, 'percentage', 2, false, 'Solicitor'],
                ],
                'titles' => [['certificate_of_occupancy', 'available'], ['registered_survey_plan', 'available'], ['excision_gazette', 'available']],
                'amenities' => ['gated-estate'],
                'media' => ['photo' => 9, 'drone' => true, 'video' => 112],
            ],
            [
                'title' => '5-Bed Detached House, Maitama',
                'area' => 'maitama', 'address' => 'Off Gana Street',
                'lat' => 9.0861, 'lng' => 7.4947, 'w3w' => '///hotels.gallery.formal',
                'type' => 'house', 'intent' => 'sale', 'finish' => 'furnished',
                'lister' => $agent, 'realsure' => true, 'days_ago' => 16,
                'description' => 'Five-bedroom detached house on a mature Maitama street, fully furnished, with staff quarters, standby generator and treated water. Suited to diplomatic or corporate occupancy.',
                'units' => [
                    ['label' => null, 'price' => 950000000, 'period' => 'once', 'beds' => 5, 'baths' => 5, 'toilets' => 6, 'sqm' => 640],
                ],
                'fees' => [
                    ['Agency fee', 47500000, 'percentage', 5, false, 'Agent'],
                    ['Legal fee', 19000000, 'percentage', 2, false, 'Solicitor'],
                    ["Governor's consent / perfection", 76000000, 'percentage', 8, false, 'FCTA'],
                ],
                'titles' => [['certificate_of_occupancy', 'available'], ['deed_of_assignment', 'available'], ['ministers_consent', 'in_progress']],
                'amenities' => ['24-hour-power', 'generator-included', 'treated-borehole', 'water-treatment-plant', 'estate-security', 'cctv', 'bq-included', 'parking-space', 'swimming-pool', 'all-rooms-ensuite'],
                'media' => ['photo' => 28, 'video' => 172, 'tour_3d' => true, 'pano_360' => true, 'drone' => true, 'floor_plan' => true],
            ],
            [
                'title' => '3-Bed Flat, Victoria Island',
                'area' => 'victoria-island', 'address' => 'Off Adeola Odeku Street',
                'lat' => 6.4295, 'lng' => 3.4256, 'w3w' => '///melon.dented.sweep',
                'type' => 'apartment', 'intent' => 'rent', 'finish' => 'furnished',
                'lister' => $developer, 'realsure' => true, 'days_ago' => 30,
                'description' => 'Fully serviced three-bedroom flat on Adeola Odeku, with lift access, twenty-four hour power, gym and pool. Service charge is billed annually in advance.',
                'units' => [
                    ['label' => null, 'price' => 15000000, 'period' => 'year', 'beds' => 3, 'baths' => 3, 'toilets' => 4, 'sqm' => 168],
                ],
                'fees' => [
                    ['Agency fee', 1500000, 'percentage', 10, false, 'Agent'],
                    ['Legal fee', 750000, 'percentage', 5, false, 'Solicitor'],
                    ['Service charge', 3500000, 'fixed', null, false, 'Facility manager'],
                    ['Caution deposit', 1500000, 'fixed', null, true, 'Landlord'],
                ],
                'titles' => [['certificate_of_occupancy', 'available'], ['deed_of_lease', 'available']],
                'amenities' => ['24-hour-power', 'elevator', 'swimming-pool', 'gym', 'estate-security', 'cctv', 'serviced-service-charge', 'parking-space', 'all-rooms-ensuite'],
                'media' => ['photo' => 22, 'video' => 121, 'tour_3d' => true, 'pano_360' => true],
            ],
        ];

        foreach ($rows as $row) {
            $this->createProperty($row, $officer);
        }
    }

    private function createProperty(array $row, User $officer): void
    {
        $area = Area::where('slug', $row['area'])->firstOrFail();
        $publishedAt = now()->subDays($row['days_ago']);

        $property = Property::create([
            'uuid' => Str::uuid(),
            'lister_id' => $row['lister']->id,
            'area_id' => $area->id,
            'title' => $row['title'],
            'slug' => Str::slug($row['title']).'-'.Str::lower(Str::random(5)),
            'description' => $row['description'],
            'listing_type' => $row['type'],
            'intent' => $row['intent'],
            'build_status' => 'fully_built',
            'finish' => $row['finish'],
            'address_line' => $row['address'],
            'city' => $area->city,
            'state' => $area->state,
            'lat' => $row['lat'],
            'lng' => $row['lng'],
            'location' => DB::raw(sprintf("ST_GeomFromText('POINT(%F %F)')", $row['lng'], $row['lat'])),
            'what3words' => $row['w3w'],
            'lifecycle_state' => 'published',
            'submitted_at' => $publishedAt->copy()->subDay(),
            'published_at' => $publishedAt,
            'expires_at' => $publishedAt->copy()->addDays(config('agentpro.display_duration_days')),
            'content_updated_at' => $publishedAt,
            'realsure_verified_at' => $row['realsure'] ? $publishedAt->copy()->subDays(3) : null,
        ]);

        foreach ($row['units'] as $i => $u) {
            $unit = Unit::create([
                'uuid' => Str::uuid(),
                'property_id' => $property->id,
                'label' => $u['label'],
                'is_primary' => $u['primary'] ?? ($i === 0),
                'price' => $u['price'],
                'price_period' => $u['period'],
                'bedrooms' => $u['beds'],
                'bathrooms' => $u['baths'],
                'toilets' => $u['toilets'],
                'floor_area_sqm' => $u['sqm'],
                'status' => $u['status'] ?? 'available',
                'available_from' => now()->addWeeks(2),
            ]);

            // A price drop only exists because history says so (FR-M2-04).
            if (isset($u['was'])) {
                $unit->priceHistory()->create([
                    'price' => $u['was'],
                    'price_period' => $u['period'],
                    'effective_at' => $publishedAt->copy()->subDays(21),
                ]);
            }
            $unit->priceHistory()->create([
                'price' => $u['price'],
                'price_period' => $u['period'],
                'effective_at' => $publishedAt,
            ]);

            // Every unit carries its own breakdown, not just the primary one.
            // FR-M7-01 is a per-unit rule, so seeding only the headline unit
            // produces inventory the platform's own compliance metric correctly
            // reports as non-compliant.
            foreach ($row['fees'] as $j => [$label, $amount, $calc, $rate, $refundable, $payee]) {
                // Percentage-based fees scale with the unit's own price.
                $scaled = $rate !== null
                    ? round($u['price'] * ($rate / 100))
                    : $amount;

                $unit->feeLines()->create([
                    'label' => $label,
                    'amount' => $scaled,
                    'calc_type' => $calc,
                    'percentage_rate' => $rate,
                    'is_refundable' => $refundable,
                    'payee' => $payee,
                    'sort_order' => $j,
                ]);
            }
        }

        foreach ($row['titles'] as [$type, $stage]) {
            TitleClaim::create([
                'property_id' => $property->id,
                'title_type' => $type,
                'stage' => $stage,
                'declared_at' => $publishedAt,
            ]);
        }

        $amenityIds = Amenity::whereIn('slug', $row['amenities'])->pluck('id');
        $property->amenities()->sync($amenityIds);

        $this->seedMedia($property, $row['media'], $publishedAt);

        if ($row['realsure']) {
            $this->seedRealsure($property, $officer, $publishedAt);
        }
    }

    private function seedMedia(Property $property, array $spec, $publishedAt): void
    {
        $sort = 0;

        foreach ($spec as $kind => $value) {
            if ($kind === 'photo') {
                for ($i = 0; $i < $value; $i++) {
                    MediaAsset::create([
                        'uuid' => Str::uuid(),
                        'property_id' => $property->id,
                        'kind' => 'photo',
                        'disk' => 'public',
                        'path' => null, // placeholder rendering until real uploads land
                        'source' => 'lister',
                        'captured_at' => $publishedAt,
                        'moderation_state' => 'approved',
                        'is_cover' => $i === 0,
                        'sort_order' => $sort++,
                    ]);
                }

                continue;
            }

            MediaAsset::create([
                'uuid' => Str::uuid(),
                'property_id' => $property->id,
                'kind' => $kind,
                'disk' => $kind === 'video' ? 'public' : null,
                'provider' => match ($kind) {
                    'tour_3d' => 'matterport',
                    'street_view' => 'google',
                    default => null,
                },
                'provider_ref' => $kind === 'tour_3d' ? 'SxQL3iGyvQk' : null,
                'duration_seconds' => $kind === 'video' ? $value : null,
                'renditions' => $kind === 'video' ? ['720p' => null, '480p' => null] : null,
                'bytes' => $kind === 'tour_3d' ? 4_194_304 : null,
                'source' => in_array($kind, ['tour_3d', 'drone'], true) ? 'agentpro_technician' : 'lister',
                'captured_at' => $publishedAt,
                'moderation_state' => 'approved',
                'sort_order' => $sort++,
            ]);
        }
    }

    private function seedRealsure(Property $property, User $officer, $publishedAt): void
    {
        // Deliberately incomplete: FR-M6-02 means an unchecked component is
        // displayed, not hidden. "No valuation commissioned" is information.
        $done = ['title_verification', 'search_report', 'regulatory_compliance', 'community_investigation', 'immersive_capture', 'hd_photography'];

        foreach (array_keys(Vocab::REALSURE_COMPONENTS) as $i => $component) {
            $completed = in_array($component, $done, true);

            RealsureRecord::create([
                'property_id' => $property->id,
                'component' => $component,
                'completed' => $completed,
                'completed_on' => $completed ? $publishedAt->copy()->subDays(14 - $i) : null,
                'officer_id' => $completed ? $officer->id : null,
            ]);
        }
    }
}
