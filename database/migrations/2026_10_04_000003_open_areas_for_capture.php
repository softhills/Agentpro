<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Open every Lagos, Abuja and Enugu area for 3D capture (FR-M4-02).
 *
 * Coverage is what decides whether the capture upgrade can be bought on a
 * listing at all — RequestScanUpgrade refuses outright outside it — so until
 * now Ajah, Gbagada, Gwarinpa and all eleven Enugu areas could not be sold a
 * capture however much a lister wanted one.
 *
 * COVERAGE IS NOT CAPACITY, and this is the part to watch. Opening an area
 * only makes the upgrade purchasable there; the dates a lister is then offered
 * come from technician_slots, and an area with no slots sends them to "No
 * dates open in <area> right now" AFTER they have paid. That is risk R2 — a
 * calendar offering more than the field team can service turns the only paid
 * feature into a queue of apologies — and the answer to it is capacity, added
 * on Coverage & capacity for each area opened here.
 *
 * Scoped to the three cities by name rather than updating every row, so an
 * area somewhere the site does not advertise is left as whoever administers it
 * left it.
 */
return new class extends Migration
{
    private const CITIES = ['Lagos', 'Abuja', 'Enugu'];

    /**
     * What ReferenceDataSeeder shipped closed, which is the best record of the
     * state before this ran. See down().
     *
     * @var list<string>
     */
    private const SHIPPED_CLOSED = [
        'ajah', 'gbagada', 'gwarinpa',
        'independence-layout', 'enugu-gra', 'new-haven', 'ogui-new-layout',
        'achara-layout', 'trans-ekulu', 'abakpa-nike', 'thinkers-corner',
        'uwani', 'asata', 'maryland-enugu',
    ];

    public function up(): void
    {
        // A new install reaches here with an empty table and the seeder, which
        // runs afterwards, now seeds every area open. Nothing to do.
        DB::table('areas')
            ->whereIn('city', self::CITIES)
            ->where('is_scan_coverage', false)
            ->update(['is_scan_coverage' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        /*
         * An approximation, and the only one available: a boolean column keeps
         * no history, so there is nothing here that knows which areas were
         * closed before this ran. Closing all three cities would be worse —
         * the ten spec areas have been open since the first seed — so this
         * restores the set the reference data shipped closed and leaves
         * everything else alone.
         *
         * An area an operator opened or closed by hand since is theirs, and
         * this cannot tell it apart. Coverage is operational state: if this
         * rollback matters, it is worth a look at Coverage & capacity
         * afterwards, where the audit log says who set what.
         */
        DB::table('areas')
            ->whereIn('city', self::CITIES)
            ->whereIn('slug', self::SHIPPED_CLOSED)
            ->update(['is_scan_coverage' => false, 'updated_at' => now()]);
    }
};
