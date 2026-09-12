<?php

namespace Tests\Support;

use App\Services\Payments\FakeGateway;
use App\Services\Payments\RefundResult;
use App\Services\Payments\SettledTransaction;
use App\Services\Payments\SettlementRecord;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A provider a test can dictate to.
 *
 * The development FakeGateway reports what is actually in the database, which
 * is right for running the app locally and useless for testing reconciliation:
 * every one of the findings that matter is a *disagreement* between the
 * provider and our records, and a gateway that derives its answers from our
 * records can never disagree with them.
 */
class StubPaymentGateway extends FakeGateway
{
    /** @var SettlementRecord[] */
    public array $records = [];

    /** @var array<string, SettledTransaction[]> */
    public array $txns = [];

    public bool $failSettlements = false;

    public bool $failTransactions = false;

    /** @var array<string, string> provider refund id => status to report */
    public array $refundStatuses = [];

    public function settlements(Carbon $from, Carbon $to): array
    {
        if ($this->failSettlements) {
            throw new RuntimeException('Paystack returned 503 for /settlement.');
        }

        return $this->records;
    }

    public function settlementTransactions(string $providerSettlementId): array
    {
        if ($this->failTransactions) {
            throw new RuntimeException('Paystack returned 500 for page 2. Refusing to reconcile against a partial list.');
        }

        return $this->txns[$providerSettlementId] ?? [];
    }

    public function fetchRefund(string $providerRefundId): ?RefundResult
    {
        if (! isset($this->refundStatuses[$providerRefundId])) {
            return parent::fetchRefund($providerRefundId);
        }

        $refund = \App\Models\Refund::where('provider_refund_id', $providerRefundId)->first();

        return new RefundResult(
            providerId: $providerRefundId,
            status: $this->refundStatuses[$providerRefundId],
            amountMinor: (int) round((float) ($refund?->amount ?? 0) * 100),
        );
    }
}
