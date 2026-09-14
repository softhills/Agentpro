<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;

/**
 * Promote an existing account to staff.
 *
 * Exists because of a gap a deployment walks straight into: the development
 * seeder refuses to run in production, so a fresh install has no staff account
 * and no way to make one. Without this the instruction would be "open
 * phpMyAdmin and set is_staff to 1" — hand-editing a privilege column, on the
 * production database, in a web UI, which is the worst available way to grant
 * the power to publish listings and move money.
 *
 * Promotes, never creates. The person signs up through the front door like
 * everybody else — real email, real password they chose, hashed by the
 * application — and this only changes what that account may do. A command that
 * created accounts would need to take a password on a command line, where it
 * lands in the shell history of a shared server.
 *
 * `is_staff` and `staff_role` are deliberately not mass-assignable (SEC-03), so
 * the assignment here is explicit and the change is written to the audit trail
 * like every other privilege change.
 */
class MakeAdmin extends Command
{
    protected $signature = 'agentpro:make-admin
                            {email : The email address of an account that already exists}
                            {--role=admin : admin, moderator, realsure_officer or technician}
                            {--revoke : Remove staff access instead of granting it}';

    protected $description = 'Grant or revoke staff access for an existing account';

    /** The roles the middleware actually understands. */
    private const ROLES = ['admin', 'moderator', 'realsure_officer', 'technician'];

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No account with that email address.');
            $this->line('Sign up at /register first, then run this against that address.');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            return $this->revoke($user);
        }

        $role = (string) $this->option('role');

        if (! in_array($role, self::ROLES, true)) {
            $this->error('Unknown role: '.$role);
            $this->line('One of: '.implode(', ', self::ROLES));

            return self::FAILURE;
        }

        $this->line('Account: '.$user->name.' <'.$user->email.'>');
        $this->line('Grants:  '.$this->describe($role));
        $this->newLine();

        if (! $this->confirm('Grant this?', false)) {
            return self::SUCCESS;
        }

        $before = ['is_staff' => (bool) $user->is_staff, 'staff_role' => $user->staff_role];

        $user->is_staff = true;
        $user->staff_role = $role;
        $user->save();

        Audit::record('user.staff_granted', $user, $before, [
            'is_staff' => true, 'staff_role' => $role,
        ]);

        $this->info($user->email.' is now '.$role.'.');

        // The one thing a new administrator cannot do for themselves, and the
        // reason a first deployment gets stuck: listings need a moderator
        // before anything reaches the public.
        if ($role === 'admin') {
            $this->line('Sign in and the console is at /admin.');
        }

        return self::SUCCESS;
    }

    private function revoke(User $user): int
    {
        if (! $user->is_staff) {
            $this->line($user->email.' is not staff.');

            return self::SUCCESS;
        }

        $before = ['is_staff' => true, 'staff_role' => $user->staff_role];

        $user->is_staff = false;
        $user->staff_role = null;
        $user->save();

        Audit::record('user.staff_revoked', $user, $before, [
            'is_staff' => false, 'staff_role' => null,
        ]);

        $this->info('Staff access removed from '.$user->email.'.');

        return self::SUCCESS;
    }

    private function describe(string $role): string
    {
        return match ($role) {
            'admin' => 'the whole console — moderation, people, orders, refunds, payouts, settlements',
            'moderator' => 'the moderation queue and listings. No access to money',
            'realsure_officer' => 'the RealSure console only — recording checks and granting the badge',
            'technician' => 'the capture assignments screen only',
        };
    }
}
