<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One browser that has agreed to receive push (FR-M9-08).
 *
 * Identified by its endpoint, which the browser mints and which is the only
 * stable handle on that installation.
 */
class PushSubscription extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'last_used_at'   => 'datetime',
        'last_failed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashFor(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * A rough device name, from the user agent.
     *
     * Only ever used to help someone recognise which of their own devices a
     * subscription is, so it errs towards something readable rather than
     * something accurate.
     */
    public function deviceLabel(): string
    {
        $agent = (string) $this->user_agent;

        $platform = match (true) {
            str_contains($agent, 'Android')            => 'Android',
            (bool) preg_match('/iPhone|iPad|iPod/', $agent) => 'iPhone or iPad',
            str_contains($agent, 'Windows')            => 'Windows',
            str_contains($agent, 'Mac OS')             => 'Mac',
            str_contains($agent, 'Linux')              => 'Linux',
            default                                    => 'Unknown device',
        };

        $browser = match (true) {
            str_contains($agent, 'Edg/')     => 'Edge',
            str_contains($agent, 'OPR/')     => 'Opera',
            str_contains($agent, 'Firefox')  => 'Firefox',
            str_contains($agent, 'Chrome')   => 'Chrome',
            str_contains($agent, 'Safari')   => 'Safari',
            default                          => null,
        };

        return $browser ? $platform.' · '.$browser : $platform;
    }
}
