<?php

namespace App\Console\Commands;

use App\Services\Push\P256;
use App\Support\Base64Url;
use Illuminate\Console\Command;

/**
 * Generate the VAPID key pair (RFC 8292).
 *
 * Prints rather than writes. Rewriting .env from a command is the kind of
 * convenience that eventually runs on a deployment and replaces a live key —
 * and replacing a VAPID key silently invalidates every push subscription in the
 * database, which nobody notices until the notifications stop.
 */
class GeneratePushKeys extends Command
{
    protected $signature = 'agentpro:push-keys';

    protected $description = 'Generate a VAPID key pair for web push';

    public function handle(): int
    {
        if ((string) config('agentpro.push.public_key') !== '') {
            $this->warn('VAPID keys are already configured.');
            $this->line(
                'Replacing them invalidates every existing push subscription — '
                .'browsers subscribe to a specific application server key. '
                .'Only generate a new pair if you mean to start over.'
            );
            $this->newLine();

            if (! $this->confirm('Generate a new pair anyway?', false)) {
                return self::SUCCESS;
            }
        }

        $pair = P256::generate();

        $this->info('Add these to .env:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.Base64Url::encode($pair['public']));
        $this->line('VAPID_PRIVATE_KEY='.Base64Url::encode(P256::scalarOf($pair['private'])));
        $this->line('VAPID_SUBJECT=mailto:ops@your-domain');
        $this->newLine();
        $this->line('The public key is served to browsers; the private key never leaves the server.');

        return self::SUCCESS;
    }
}
