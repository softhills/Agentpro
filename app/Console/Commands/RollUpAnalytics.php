<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fold raw events into daily totals, then throw the raw events away (M13).
 *
 * The discarding is the point, not a side effect. Raw behavioural events are
 * the only rows on the platform that describe what somebody did minute by
 * minute, and NDPA's data-minimisation principle says they should not be kept
 * for longer than the purpose needs. The purpose is reporting, reporting needs
 * daily figures, and daily figures are what survives — so the retention window
 * is short and the reports are unaffected by it.
 *
 * It recomputes whole days rather than accumulating. Running it twice must
 * produce the same numbers as running it once, and an aggregate built by
 * incrementing is an aggregate that silently doubles the first time a cron
 * overlaps, with nothing in the data to say it happened.
 */
class RollUpAnalytics extends Command
{
    protected $signature = 'agentpro:roll-up-analytics {--days=3 : How many recent days to recompute}';

    protected $description = 'Roll analytics events into daily totals and prune events past the retention window';

    public function handle(): int
    {
        /*
         * Several days, not just yesterday. A visitor whose tour dwell arrives
         * after midnight, a queue that was down, a server whose clock drifted —
         * each lands an event in a day that has already been rolled up, and a
         * job that only ever looks at yesterday would never revisit it.
         */
        $days = max(1, (int) $this->option('days'));

        for ($i = 0; $i < $days; $i++) {
            $this->rollUp(now()->subDays($i)->startOfDay());
        }

        $this->prune();

        return self::SUCCESS;
    }

    private function rollUp(Carbon $day): void
    {
        $from = $day->copy()->startOfDay();
        $to   = $day->copy()->endOfDay();

        $rows = DB::table('analytics_events')
            ->whereBetween('occurred_at', [$from, $to])
            ->groupBy('name', 'property_id')
            ->get([
                'name',
                'property_id',
                DB::raw('COUNT(*) AS events'),
                DB::raw('SUM(value) AS value_sum'),
                DB::raw('COUNT(value) AS value_count'),
                DB::raw('COUNT(visitor_id) AS consented'),
            ]);

        DB::transaction(function () use ($rows, $from) {
            /*
             * Delete and rewrite, inside one transaction. A recompute that
             * cleared the day and then failed would leave a hole that looks
             * exactly like a day with no traffic, and nothing downstream could
             * tell the difference.
             */
            DB::table('analytics_daily')->where('day', $from->toDateString())->delete();

            foreach ($rows->chunk(500) as $chunk) {
                DB::table('analytics_daily')->insert(
                    $chunk->map(fn ($row) => [
                        'day'         => $from->toDateString(),
                        'name'        => $row->name,
                        'property_id' => $row->property_id,
                        'events'      => $row->events,
                        // Null rather than 0 where nothing carried a value, so
                        // "no measurements" cannot be read as "measured zero".
                        'value_sum'   => $row->value_count > 0 ? $row->value_sum : null,
                        'value_count' => $row->value_count > 0 ? $row->value_count : null,
                        'consented'   => $row->consented,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ])->all()
                );
            }
        });

        $this->line($from->toDateString().': '.$rows->count().' rolled up.');
    }

    /**
     * Drop raw events older than the retention window.
     *
     * In chunks, because a single DELETE across a few million rows holds locks
     * long enough to be felt on the pages still writing to this table — and the
     * one thing this job must not do is make the site slow in order to tidy up
     * after it.
     */
    private function prune(): void
    {
        $cutoff = now()->subDays((int) config('agentpro.analytics.retention_days'))->startOfDay();
        $deleted = 0;

        do {
            $batch = DB::table('analytics_events')
                ->where('occurred_at', '<', $cutoff)
                ->limit(5000)
                ->delete();

            $deleted += $batch;
        } while ($batch > 0);

        $this->info($deleted.' raw events older than '.$cutoff->toDateString().' removed.');
    }
}
