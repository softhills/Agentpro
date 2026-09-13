<?php

namespace App\Services\Push;

use App\Models\PushSubscription;

/**
 * Delivery to one browser subscription (FR-M9-08).
 *
 * An interface for the same reason the payment gateway is one: the two
 * properties worth protecting — that a body is always encrypted to the
 * subscription, and that a dead subscription is pruned rather than retried
 * forever — belong to our code, not to a driver.
 */
interface WebPushSender
{
    public function send(PushSubscription $subscription, array $payload): PushDelivery;

    /** The application server key a browser needs in order to subscribe. */
    public function publicKey(): ?string;
}
