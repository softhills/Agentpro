<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Base for everything the platform sends (FR-M9-07).
 *
 * Channel routing lives here so that preferences and quiet hours are applied
 * once, consistently. A notification that decided its own channels would
 * eventually be the one that ignored somebody's settings at 2am.
 *
 * The in-app inbox ('database') is always included and is never suppressed:
 * it does not interrupt anyone, and it means a held-back push still leaves a
 * record the user can find.
 */
abstract class AgentproNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Channels this notification is willing to use, before preferences.
     *
     * @return list<string>
     */
    abstract protected function preferredChannels(): array;

    /**
     * Ignores quiet hours.
     *
     * True only where holding the message back is worse than the interruption:
     * a technician arriving at your gate, or a payment receipt whose absence
     * looks like a failed payment.
     */
    protected function isUrgent(): bool
    {
        return false;
    }

    /** Preference key that switches this category off entirely, if any. */
    protected function category(): ?string
    {
        return null;
    }

    final public function via(object $notifiable): array
    {
        $preferences = NotificationPreferences::for($notifiable);

        if ($this->category() && ! $preferences->wants($this->category())) {
            return [];
        }

        $channels = $preferences->resolve($this->preferredChannels(), $this->isUrgent());

        // Deduplicated: push and SMS currently fall back to the inbox, which is
        // already in the list, and a repeated driver would deliver twice.
        return array_values(array_unique(array_merge(
            ['database'],
            array_map(fn (string $c) => $this->driverFor($c), $channels)
        )));
    }

    /**
     * FR-M9-07: a one-tap unsubscribe on every message that goes out by email.
     *
     * Signed, so it works from a mail client with no session and cannot be
     * altered to unsubscribe somebody else.
     *
     * There is a real tension here. A blanket off-switch on a receipt or a
     * verification decision means someone stops receiving things they actually
     * need, so those carry a link that turns off *this category* where one
     * exists, and otherwise points at the settings page rather than silently
     * disabling everything. Only a category-scoped notification gets the true
     * one-tap kill.
     */
    protected function withUnsubscribe(MailMessage $mail, object $notifiable): MailMessage
    {
        if ($this->category()) {
            return $mail->line(
                '[Stop these emails]('
                .URL::signedRoute('unsubscribe', [
                    'user' => $notifiable->id,
                    'category' => $this->category(),
                ])
                .') · [All notification settings]('.route('notifications.edit').')'
            );
        }

        return $mail->line(
            'This is a service message about your account. '
            .'[Manage how we contact you]('.route('notifications.edit').')'
        );
    }

    private function driverFor(string $channel): string
    {
        return match ($channel) {
            'email'    => 'mail',
            'whatsapp' => WhatsAppChannel::class,
            // Web push and SMS are not wired yet. Routing them to the inbox
            // rather than a missing driver keeps the message reaching the user
            // instead of throwing on send.
            default    => 'database',
        };
    }
}
