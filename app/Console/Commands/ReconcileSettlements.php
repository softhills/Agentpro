<?php

namespace App\Console\Commands;

use App\Actions\ReconcileSettlements as Reconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * FR-M11-06.
 *
 * Exits non-zero when the run found something that needs a person, so a
 * scheduler or CI job surfaces it without anyone having to read the output.
 * A reconciliation that always exits 0 is a reconciliation nobody reads.
 */
class ReconcileSettlements extends Command
{
    protected $signature = 'agentpro:reconcile-settlements
        {--days= : how far back to look (defaults to the configured window)}
        {--from= : explicit start date, YYYY-MM-DD}
        {--to= : explicit end date, YYYY-MM-DD}';

    protected $description = 'Match provider settlements against orders and chase refunds still in flight';

    public function handle(Reconciler $reconciler): int
    {
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : now();

        $from = match (true) {
            (bool) $this->option('from') => Carbon::parse($this->option('from')),
            (bool) $this->option('days') => $to->copy()->subDays((int) $this->option('days')),
            default                      => null,
        };

        $report = $reconciler->run($from, $to);

        $this->line($report->summary());

        foreach ($report->problems as $problem) {
            $this->warn('  · '.$problem);
        }

        if ($report->orphans > 0) {
            $this->error(sprintf(
                '%d settled %s no order behind %s — somebody paid and this system does not know.',
                $report->orphans,
                $report->orphans === 1 ? 'transaction has' : 'transactions have',
                $report->orphans === 1 ? 'it' : 'them',
            ));
        }

        if ($report->unsettledOrders > 0) {
            $this->error(sprintf(
                '%d paid %s never settled.',
                $report->unsettledOrders,
                $report->unsettledOrders === 1 ? 'order has' : 'orders have',
            ));
        }

        if ($report->isClean() && $report->unsettledOrders === 0) {
            $this->info('Everything reconciles.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
