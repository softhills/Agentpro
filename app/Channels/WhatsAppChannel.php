<?php

namespace App\Channels;

use App\Services\Messaging\WhatsAppSender;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a notification over WhatsApp, if it offers a template.
 *
 * A notification opts in by implementing toWhatsApp(), which returns the
 * approved template name and its variables. Anything that does not is simply
 * not a WhatsApp message, and that is decided here rather than by each caller.
 */
class WhatsAppChannel
{
    public function __construct(private WhatsAppSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $phone = $notifiable->phone ?? null;

        if (! $phone) {
            Log::info('whatsapp.skipped_no_number', ['notifiable' => $notifiable->id ?? null]);

            return;
        }

        $message = $notification->toWhatsApp($notifiable);

        $this->sender->sendTemplate($phone, $message['template'], $message['variables'] ?? []);
    }
}
