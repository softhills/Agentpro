<?php

namespace App\Services\Messaging;

/**
 * SMS gateway (FR-M9-08).
 *
 * An interface because the gateway choice is genuinely open and genuinely local:
 * Termii, Africa's Talking and Twilio all reach Nigerian networks and differ in
 * price, in how they handle DND, and in whether a sender ID has to be
 * pre-registered. Binding the choice in one place means switching provider after
 * a price change is a container binding, not a rewrite.
 */
interface SmsSender
{
    /**
     * @param  string  $toE164  a normalised number — the caller has already checked it
     * @param  bool  $transactional  route around Do Not Disturb, where the gateway allows it
     */
    public function send(string $toE164, string $body, bool $transactional = true): SmsDelivery;

    public function name(): string;
}
