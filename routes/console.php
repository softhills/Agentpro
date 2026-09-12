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

/*
 | FR-M5-07: saved-search matching.
 |
 | Two cadences rather than one. 'instant' is a promise about responsiveness in
 | a market where good listings go quickly; 'daily' exists because a broad
 | search on instant would be a stream, and a stream gets muted.
 */
Schedule::command('agentpro:run-saved-searches --frequency=instant')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('agentpro:run-saved-searches --frequency=daily')
    ->dailyAt('08:00')
    ->withoutOverlapping();
