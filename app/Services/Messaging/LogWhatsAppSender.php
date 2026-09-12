<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Log;

/**
 * Development sender.
 *
 * Writes what would have been sent and reports success, so the rest of the
 * notification path is exercised without a Meta account. It deliberately does
 * not silently no-op: an un-sent message that leaves no trace is indis-
 * tinguishable from a bug in the fan-out.
 */
class LogWhatsAppSender implements WhatsAppSender
{
    public function sendTemplate(string $toPhone, string $template, array $variables): bool
    {
        Log::info('whatsapp.template.would_send', [
            'to'        => $toPhone,
            'template'  => $template,
            'variables' => $variables,
        ]);

        return true;
    }
}
