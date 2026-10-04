<?php

namespace App\Console\Commands;

use App\Models\Area;
use App\Models\TechnicianSlot;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Open capture capacity across several areas at once (FR-M4-05).
 *
 * Coverage & capacity opens slots in one area per submission, which is the
 * right shape for the everyday job — a technician's next fortnight in Lekki.
 * It is the wrong shape the day a city opens: fourteen areas became
 * purchasable at once, and fourteen trips through the same form is how one
 * gets missed. A missed one is not a blank screen either. Coverage is what
 * makes the upgrade *purchasable*, so an area open with no slots behind it
 * sells a capture and then tells the lister there are no dates — after they
 * have paid.
 *
 * WHAT IT WILL NOT DO IS GUESS. There is no default technician and no default
 * for how much a day can hold, because a calendar is a promise about people:
 * every slot opened here says someone will drive to a property and spend two
 * hours capturing it. Risk R2 in the project's own words is that a calendar
 * offering more than the field team delivers turns the only paid feature into
 * a queue of apologies. The defaults it does carry are the house ones — two
 * visits a day, Sundays off, a fortnight out — which is roughly what one
 * technician manages in Lagos traffic, and they are a starting point for
 * someone who knows the rota, not a substitute for one.
 *
 * Safe to run twice: slots are matched on the key the schema already makes
 * unique (area, technician, date, start time), so a second run over an
 * overlapping fortnight adds the days that were missing and leaves the rest,
 * including anything already booked.
 */
class OpenCapacity extends Command
{
    protected $signature = 'agentpro:open-capacity
                            {--technician= : Email of the technician these visits belong to}
                            {--area=* : Area slug, repeatable. Default: every area open for capture with nothing bookable}
                            {--city=* : City name, repeatable — every area in it}
                            {--from= : First date, Y-m-d. Default tomorrow}
                            {--days=14 : Days to cover from that date, Sundays skipped}
                            {--times=09:00,13:00 : Visit start times each day, comma separated}
                            {--capacity=1 : Visits each slot can take}
                            {--dry-run : Show what would be opened and write nothing}
                            {--force : Skip the confirmation, for a deploy script}';

    protected $description = 'Open capture slots across areas that have none';

    public function handle(): int
    {
        $technician = $this->technician();

        if (! $technician instanceof User) {
            return self::FAILURE;
        }

        $areas = $this->areas();

        if ($areas === null) {
            return self::FAILURE;
        }

        if ($areas->isEmpty()) {
            $this->info('Every area open for capture already has dates in it. Nothing to do.');

            return self::SUCCESS;
        }

        $times = $this->times();

        if ($times === null) {
            return self::FAILURE;
        }

        $from = $this->from();

        if (! $from instanceof Carbon) {
            return self::FAILURE;
        }

        $days     = max(1, (int) $this->option('days'));
        $capacity = max(1, (int) $this->option('capacity'));
        $dates    = $this->dates($from, $days);

        // Sundays come out of the range, and a short enough range can be
        // nothing but Sunday. Refused rather than reported as an empty success,
        // because whoever asked for it meant to open something.
        if ($dates->isEmpty()) {
            $this->error('Nothing but Sunday in those '.$days.' '.str('day')->plural($days)
                .', and Sundays are not worked. Start a day later or ask for more days.');

            return self::FAILURE;
        }

        $this->line('Technician: '.$technician->name.' <'.$technician->email.'>');
        $this->line('Dates:      '.$dates->first()->format('D j M').' to '.$dates->last()->format('D j M')
            .' — '.$dates->count().' '.str('day')->plural($dates->count()).', Sundays skipped');
        $this->line('Times:      '.implode(', ', $times).' — '.$capacity.' per slot');
        $this->newLine();

        $perArea = $dates->count() * count($times);

        $this->table(
            ['Area', 'City', 'Open for capture', 'Bookable now', 'Slots to open'],
            $areas->map(fn (Area $area) => [
                $area->name,
                $area->city,
                $area->is_scan_coverage ? 'yes' : 'NO — nothing can be booked',
                $area->technicianSlots()->open()->sum('capacity') - $area->technicianSlots()->open()->sum('booked'),
                $perArea,
            ])->all(),
        );

        $this->line('At most '.($perArea * $areas->count()).' slots across '.$areas->count()
            .' '.str('area')->plural($areas->count()).'. Visits that already exist are left alone.');
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        // Asked for, not assumed: the default is no, and --no-interaction on
        // its own therefore writes nothing. A script that means it says --force.
        if (! $this->option('force') && ! $this->confirm('Open them?', false)) {
            return self::SUCCESS;
        }

        $opened = 0;

        foreach ($areas as $area) {
            $created = $this->openIn($area, $technician, $dates, $times, $capacity);
            $opened += $created;

            $this->line(sprintf('  %-28s %d new, %d already there', $area->name, $created, $perArea - $created));
        }

        $this->newLine();
        $this->info($opened.' '.str('slot')->plural($opened).' opened.');

        if ($areas->contains(fn (Area $area) => ! $area->is_scan_coverage)) {
            $this->warn('Some of those areas are closed for capture, so nothing can be booked against them'
                .' until they are opened on Coverage & capacity.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int,Carbon>  $dates
     * @param  list<string>  $times
     */
    private function openIn(Area $area, User $technician, $dates, array $times, int $capacity): int
    {
        $created = 0;

        foreach ($dates as $date) {
            foreach ($times as $time) {
                /*
                 * Looked for on the key the schema makes unique before being
                 * inserted, so a second run over an overlapping fortnight adds
                 * what was missing instead of failing halfway through on a
                 * unique violation — which would leave the areas it had
                 * already reached done and the rest not.
                 *
                 * whereDate rather than a plain where on the string, because
                 * what is stored for a given day depends on the driver: MySQL
                 * truncates to its DATE column, sqlite keeps the whole
                 * "2026-10-05 00:00:00" the model's date cast hands it, and an
                 * equality test that matches on one silently misses on the
                 * other. Asking for the date of the value compares days on
                 * both.
                 */
                $exists = TechnicianSlot::where('area_id', $area->id)
                    ->where('technician_id', $technician->id)
                    ->whereDate('slot_date', $date->toDateString())
                    ->where('slot_start', $time.':00')
                    ->exists();

                if ($exists) {
                    continue;
                }

                TechnicianSlot::create([
                    'area_id'       => $area->id,
                    'technician_id' => $technician->id,
                    'slot_date'     => $date->toDateString(),
                    'slot_start'    => $time.':00',
                    'capacity'      => $capacity,
                    'booked'        => 0,
                ]);

                $created++;
            }
        }

        // The same action the console records, so the log reads the same
        // whichever door the capacity came through. actor_id is null from a
        // terminal, so the payload says where it came from instead.
        Audit::record('area.slots_added', $area, [], [
            'created'    => $created,
            'technician' => $technician->email,
            'via'        => 'agentpro:open-capacity',
        ]);

        return $created;
    }

    private function technician(): ?User
    {
        $email = (string) $this->option('technician');

        if ($email === '') {
            $this->error('Which technician? Pass --technician=<email>.');
            $this->listTechnicians();

            return null;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error('No account with that email address.');
            $this->listTechnicians();

            return null;
        }

        /*
         * staff_role rather than isStaff('technician'), which an admin also
         * satisfies. A slot names the person who turns up at the property; an
         * administrator passing the permission check is not the same thing as
         * an administrator driving to Gbagada.
         */
        if ($user->staff_role !== 'technician') {
            $this->error($user->email.' is not a technician'
                .($user->staff_role ? ' — that account is '.$user->staff_role.'.' : '.'));
            $this->line('Grant the role with: php artisan agentpro:make-admin '.$user->email.' --role=technician');
            $this->listTechnicians();

            return null;
        }

        return $user;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int,Area>|null */
    private function areas()
    {
        $slugs  = (array) $this->option('area');
        $cities = (array) $this->option('city');

        if ($slugs === [] && $cities === []) {
            /*
             * The default is the situation this exists for: open for capture,
             * nothing bookable in it. That is the row Coverage & capacity
             * marks in red, and after a city is opened it is every area in it.
             */
            return Area::scanCoverage()
                ->whereDoesntHave('technicianSlots', fn ($q) => $q->open())
                ->orderBy('city')
                ->orderBy('name')
                ->get();
        }

        /*
         * A city is how this is actually talked about — "capacity for Enugu" —
         * and naming its eleven areas one --area at a time is how the twelfth
         * gets left out. Either selector, or both; what matches once is opened
         * once.
         */
        $wanted = array_map(mb_strtolower(...), $cities);

        $areas = Area::query()
            ->where(function ($q) use ($slugs, $wanted) {
                if ($slugs !== []) {
                    $q->orWhereIn('slug', $slugs);
                }

                if ($wanted !== []) {
                    // Lowered on both sides: an operator types "enugu", the
                    // column holds "Enugu", and MySQL would not care but
                    // sqlite would.
                    $q->orWhereIn(DB::raw('LOWER(city)'), $wanted);
                }
            })
            ->orderBy('city')
            ->orderBy('name')
            ->get();

        $missingSlugs = array_diff($slugs, $areas->pluck('slug')->all());

        if ($missingSlugs !== []) {
            $this->error('No such '.str('area')->plural(count($missingSlugs)).': '.implode(', ', $missingSlugs));

            return null;
        }

        $found = $areas->map(fn (Area $area) => mb_strtolower($area->city))->unique()->all();
        $missingCities = array_diff($wanted, $found);

        if ($missingCities !== []) {
            $this->error('No areas in: '.implode(', ', $missingCities));
            $this->line('Cities: '.Area::query()->distinct()->orderBy('city')->pluck('city')->implode(', '));

            return null;
        }

        return $areas;
    }

    /** @return list<string>|null */
    private function times(): ?array
    {
        $times = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('times')),
        )));

        foreach ($times as $time) {
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                $this->error('Not a time of day: '.$time.'. Use 24-hour HH:MM, like 09:00.');

                return null;
            }
        }

        if ($times === []) {
            $this->error('No times given.');

            return null;
        }

        return $times;
    }

    private function from(): ?Carbon
    {
        $from = $this->option('from');

        if ($from === null) {
            return Carbon::tomorrow();
        }

        try {
            $date = Carbon::parse((string) $from)->startOfDay();
        } catch (\Throwable) {
            $this->error('Not a date: '.$from.'. Use Y-m-d, like '.now()->addDay()->toDateString().'.');

            return null;
        }

        if ($date->isBefore(Carbon::today())) {
            $this->error('That date has passed. Nobody can book a visit in the past.');

            return null;
        }

        return $date;
    }

    /** @return \Illuminate\Support\Collection<int,Carbon> */
    private function dates(Carbon $from, int $days)
    {
        return collect(range(0, $days - 1))
            ->map(fn (int $offset) => $from->copy()->addDays($offset))
            ->reject(fn (Carbon $date) => $date->isSunday())
            ->values();
    }

    private function listTechnicians(): void
    {
        $technicians = User::where('staff_role', 'technician')->orderBy('name')->get(['name', 'email']);

        if ($technicians->isEmpty()) {
            $this->line('There are no technician accounts yet.');

            return;
        }

        $this->newLine();
        $this->line('Technicians:');

        foreach ($technicians as $technician) {
            $this->line('  '.$technician->name.' <'.$technician->email.'>');
        }
    }
}
