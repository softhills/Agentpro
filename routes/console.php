<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 | FR-M9-06: close the batching windows and fan out listing alerts.
 |
 | Every five minutes rather than continuously, because batching is the point —
 | the delay is what turns four edits in one sitting into one notification.
 */
Schedule::command('agentpro:dispatch-listing-alerts')
    ->everyFiveMinutes()
    ->withoutOverlapping();
