<?php

namespace Tests\Feature;

use App\Actions\ExportAccountData;
use App\Models\User;
use App\Support\PersonalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The guard on the privacy manifest (FR-M1-09).
 *
 * Every other test in this suite checks that the code does what it says. These
 * check that the manifest still describes the database — which is the failure
 * that would actually happen. Nobody is going to break the erasure code; what
 * happens is that somebody adds a table with a `user_id` on it eighteen months
 * from now, every privacy test keeps passing, and the platform quietly stops
 * honouring a statutory right on that table.
 *
 * So this reads the live schema rather than a list, and fails on a table it has
 * not been told about. It is deliberately annoying: adding a table that holds
 * personal data should require a decision about what erasure does to it, and a
 * red test is the cheapest way to force one.
 */
class PersonalDataTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tables that are Laravel's or the queue's, not the product's.
     *
     * None of them hold anything about a person that survives its own lifetime —
     * a cache entry, a queued payload, a batch record — and a failed job is
     * deleted by the queue rather than by us.
     *
     * @var list<string>
     */
    private const FRAMEWORK = [
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    ];

    /**
     * Tables that hold a person's data without a foreign key to say so.
     *
     * Listed by hand because no amount of schema reading will find them: a
     * session points at a user by an unconstrained integer, a reset token by
     * email address, and a notification by a polymorphic pair. These are
     * exactly the tables a privacy feature forgets.
     *
     * @var list<string>
     */
    private const UNLINKED = ['sessions', 'password_reset_tokens', 'notifications'];

    public function test_every_table_holding_personal_data_has_been_decided_about(): void
    {
        $missing = $this->undecidedTables();

        $this->assertSame([], $missing,
            'These tables reference a user but PersonalData::map() does not say what erasure does '
            .'to them. Add an entry — and make a real decision about the disposal, because the '
            .'erasure now runs on whatever you write: '.implode(', ', $missing));
    }

    /**
     * The check above passes today, which on its own proves nothing: a guard
     * that cannot fail is decoration. This adds the table the guard is meant to
     * catch and confirms it is caught.
     */
    public function test_the_guard_notices_a_new_table_nobody_has_decided_about(): void
    {
        Schema::create('viewing_appointments', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
        });

        try {
            $this->assertContains('viewing_appointments', $this->undecidedTables(),
                'A new table with a user_id went unnoticed, which means the guard would not '
                .'catch the one real mistake this feature is exposed to.');
        } finally {
            Schema::drop('viewing_appointments');
        }
    }

    public function test_the_manifest_does_not_name_tables_that_no_longer_exist(): void
    {
        foreach (array_keys(PersonalData::map()) as $table) {
            $this->assertTrue(Schema::hasTable($table),
                "PersonalData::map() names `{$table}`, which is not in the schema. A stale entry is "
                .'worse than a missing one: it reads as a decision that is being enforced when nothing '
                .'is enforcing it.');
        }
    }

    public function test_every_section_the_manifest_promises_appears_in_the_export(): void
    {
        $user = User::forceCreate([
            'uuid' => Str::uuid(), 'name' => 'Amaka Obi',
            'email' => 'amaka@example.test', 'password' => 'x', 'category' => 'seeker',
        ]);

        $payload = app(ExportAccountData::class)->build($user);

        foreach (PersonalData::map() as $table => $entry) {
            if ($entry['section'] === null) {
                continue;
            }

            $this->assertArrayHasKey($entry['section'], $payload,
                "The manifest says `{$table}` is exported as '{$entry['section']}', but the export "
                .'has no such section. The right of access and the right to erasure have to cover '
                .'the same ground, or the export is telling people less than we hold.');
        }
    }

    public function test_the_export_explains_every_section_it_contains(): void
    {
        $user = User::forceCreate([
            'uuid' => Str::uuid(), 'name' => 'Amaka Obi',
            'email' => 'amaka2@example.test', 'password' => 'x', 'category' => 'seeker',
        ]);

        $payload = app(ExportAccountData::class)->build($user);
        $notes = $payload['what_each_section_is'];

        // Everything except the two framing sections is data, and a reader who
        // cannot tell what a section is or why we have it has been handed a
        // dump rather than an answer.
        $sections = array_diff(array_keys($payload), ['about_this_file', 'what_each_section_is']);

        foreach ($sections as $section) {
            $this->assertArrayHasKey($section, $notes,
                "The export contains a '{$section}' section that nothing explains.");
            $this->assertNotEmpty($notes[$section]['why_we_keep_it']);
        }
    }

    /**
     * Tables that reference a user and are not in the manifest.
     *
     * @return list<string>
     */
    private function undecidedTables(): array
    {
        $manifest = array_keys(PersonalData::map());
        $missing = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            if (in_array($name, self::FRAMEWORK, true) || in_array($name, $manifest, true)) {
                continue;
            }

            $pointsAtUsers = in_array($name, self::UNLINKED, true)
                || collect(Schema::getForeignKeys($name))
                    ->contains(fn (array $key) => $key['foreign_table'] === 'users');

            if ($pointsAtUsers) {
                $missing[] = $name;
            }
        }

        return $missing;
    }
}
