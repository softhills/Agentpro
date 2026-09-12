<?php

namespace App\Services\Messaging;

/**
 * WhatsApp Business API (FR-M9-08).
 *
 * Business-initiated messages are only permitted through templates Meta has
 * approved in advance, and only outside the 24-hour service window opened by a
 * user's own message. That is a product constraint, not an implementation
 * detail: the copy has to be written and submitted weeks before it can be sent,
 * which is why this interface takes a template name and variables rather than a
 * string of prose.
 */
interface WhatsAppSender
{
    /**
     * @param  array<string,string>  $variables  values for the approved template
     */
    public function sendTemplate(string $toPhone, string $template, array $variables): bool;
}
