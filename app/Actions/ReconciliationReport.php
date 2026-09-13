<?php

namespace App\Actions;

/**
 * What one reconciliation run found.
 *
 * Counts rather than rows, because the rows live in the database afterwards and
 * this is what the command prints and the scheduler logs.
 */
final class ReconciliationReport
{
    public function __construct(
        public int $settlements = 0,
        public int $transactions = 0,
        public int $matched = 0,
        public int $orphans = 0,
        public int $unsettledOrders = 0,
        public int $refundsPolled = 0,
        public int $refundsResolved = 0,
        public int $payoutsPolled = 0,
        public int $payoutsResolved = 0,
        public int $discrepancies = 0,
        /** @var string[] */
        public array $problems = [],
    ) {}

    /** Did anything on this run need a person? */
    public function isClean(): bool
    {
        return $this->orphans === 0
            && $this->discrepancies === 0
            && $this->problems === [];
    }

    public function summary(): string
    {
        return sprintf(
            '%d settlements, %d transactions (%d matched, %d unmatched); %d paid orders unsettled; '
            .'%d refunds polled, %d resolved; %d payouts polled, %d resolved.',
            $this->settlements,
            $this->transactions,
            $this->matched,
            $this->orphans,
            $this->unsettledOrders,
            $this->refundsPolled,
            $this->refundsResolved,
            $this->payoutsPolled,
            $this->payoutsResolved,
        );
    }
}
