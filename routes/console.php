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

/*
 | FR-M11-06: settlement reconciliation.
 |
 | Early, before anyone is working, so Finance opens the settlements screen to
 | a picture of yesterday rather than to a stale one. It reads a rolling window
 | rather than a single day, so a settlement the provider amends after the fact
 | is picked up on the next run instead of being missed forever.
 */
Schedule::command('agentpro:reconcile-settlements')
    ->dailyAt('06:30')
    ->withoutOverlapping();

/*
 | FR-M1-09: carry out erasures whose cooling-off has ended, and delete exports
 | nobody collected.
 |
 | Hourly rather than daily, because the cooling-off period is a promise with a
 | time on it — "your account closes at 6pm on Thursday" should not mean 6am on
 | Friday. It also keeps uncollected export files, each a complete copy of
 | somebody's account, from sitting around for most of a day after they expire.
 */
Schedule::command('agentpro:run-data-requests')
    ->hourly()
    ->withoutOverlapping();

/*
 | M13: fold yesterday's events into daily totals, then prune the raw ones.
 |
 | At 03:00 rather than midnight. Rolling up a day the moment it ends races
 | every event still being written by somebody browsing at 23:59:59, and the
 | pruning at the end of this job competes with live traffic for locks — both
 | arguments point at the quietest hour rather than the neatest one.
 */
Schedule::command('agentpro:roll-up-analytics')
    ->dailyAt('03:00')
    ->withoutOverlapping();
