<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Per-channel notification preferences with quiet hours (FR-M9-07).
 *
 * Wraps the JSON column so the shape lives in one place rather than being
 * re-guessed at every call site.
 *
 * Two decisions are encoded here rather than left to each notification:
 *
 *  - Channels default to *on* for email and push and *off* for WhatsApp and
 *    SMS. Both of the latter cost money per message and land somewhere more
 *    intrusive, so they are opt-in.
 *  - Some notifications ignore quiet hours entirely. A technician arriving at
 *    your gate at 09:00 is not something to sit on until the evening, and a
 *    payment receipt held back looks like a payment that failed.
 */
final class NotificationPreferences
{
    public const CHANNELS = ['push', 'email', 'whatsapp', 'sms'];

    private const DEFAULTS = [
        'push'     => true,
        'email'    => true,
        'whatsapp' => false,
        'sms'      => false,
        'quiet_from' => '21:00',
        'quiet_to'   => '07:00',
        'quiet_enabled' => true,
        // FR-M9-05: alerts about listings the seeker has interacted with.
        'listing_updates' => true,
        // FR-M5-07: saved-search matches.
        'saved_searches'  => true,
    ];

    private array $values;

    public function __construct(?array $stored)
    {
        $this->values = array_merge(self::DEFAULTS, $stored ?? []);
    }

    public static function for(User $user): self
    {
        return new self($user->notification_preferences);
    }

    public function enabled(string $channel): bool
    {
        return (bool) ($this->values[$channel] ?? false);
    }

    public function wants(string $category): bool
    {
        return (bool) ($this->values[$category] ?? true);
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Is now inside the user's quiet window?
     *
     * Handles the overnight case, where the window wraps past midnight — 21:00
     * to 07:00 is the default and the naive comparison gets it exactly backwards.
     */
    public function isQuietAt(?Carbon $moment = null): bool
    {
        if (! $this->values['quiet_enabled']) {
            return false;
        }

        $moment ??= Carbon::now(config('app.timezone'));
        $now = (int) $moment->format('Hi');

        $from = (int) str_replace(':', '', (string) $this->values['quiet_from']);
        $to   = (int) str_replace(':', '', (string) $this->values['quiet_to']);

        return $from <= $to
            ? ($now >= $from && $now < $to)          // same-day window
            : ($now >= $from || $now < $to);         // wraps past midnight
    }

    /**
     * The channels a given notification should actually go out on.
     *
     * @param  list<string>  $preferred  channels this notification can use
     * @return list<string>
     */
    public function resolve(array $preferred, bool $urgent = false): array
    {
        $quiet = ! $urgent && $this->isQuietAt();

        return array_values(array_filter($preferred, function (string $channel) use ($quiet) {
            if (! $this->enabled($channel)) {
                return false;
            }

            // During quiet hours the interruptive channels are held back; the
            // inbox and email still arrive, because they wait to be read.
            return ! ($quiet && in_array($channel, ['push', 'sms', 'whatsapp'], true));
        }));
    }

    public static function fromForm(array $input): array
    {
        $values = [];

        foreach (self::CHANNELS as $channel) {
            $values[$channel] = (bool) ($input[$channel] ?? false);
        }

        $values['quiet_enabled']   = (bool) ($input['quiet_enabled'] ?? false);
        $values['quiet_from']      = $input['quiet_from'] ?? self::DEFAULTS['quiet_from'];
        $values['quiet_to']        = $input['quiet_to'] ?? self::DEFAULTS['quiet_to'];
        $values['listing_updates'] = (bool) ($input['listing_updates'] ?? false);
        $values['saved_searches']  = (bool) ($input['saved_searches'] ?? false);

        return $values;
    }
}
