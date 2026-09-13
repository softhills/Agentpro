<?php

namespace App\Services\Push;

/**
 * What a push service said.
 *
 * `gone` is the one that matters operationally. A browser that has been
 * uninstalled, cleared or revoked returns 404 or 410 forever, and a subscription
 * nobody prunes is a queued job and an HTTP round trip repeated for every
 * notification, indefinitely.
 */
final class PushDelivery
{
    public function __construct(
        public readonly bool $delivered,
        public readonly int $status = 0,
        public readonly bool $gone = false,
        public readonly ?string $error = null,
    ) {}

    public static function sent(int $status = 201): self
    {
        return new self(delivered: true, status: $status);
    }

    public static function expired(int $status): self
    {
        return new self(delivered: false, status: $status, gone: true, error: 'The subscription no longer exists.');
    }

    public static function failed(string $error, int $status = 0): self
    {
        return new self(delivered: false, status: $status, error: $error);
    }
}
