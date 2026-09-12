<?php

namespace App\Http\Controllers\Admin;

use App\Actions\IssueRefund;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Refund;
use Illuminate\Http\Request;
use RuntimeException;

/** FR-M11-04, FR-M11-05: order history and refunds. */
class OrderAdminController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.orders', [
            'orders' => Order::query()
                ->when($request->filled('state'), fn ($q) => $q->where('state', $request->query('state')))
                ->when($request->query('view') === 'unsettled', fn ($q) => $q
                    ->whereIn('state', ['paid', 'partially_refunded', 'refunded'])
                    ->whereNull('settlement_id')
                    ->where('paid_at', '<', now()->subDays((int) config('agentpro.settlement.grace_days'))))
                ->with([
                    'user:id,name,email',
                    'property:id,uuid,title',
                    'scanJob',
                    'settlement:id,provider_id,settlement_date,status',
                    'refunds' => fn ($q) => $q->with('requester:id,name')->latest('id'),
                ])
                ->orderByDesc('id')
                ->paginate(30)
                ->withQueryString(),
            'totals' => [
                'paid'       => (float) Order::where('state', 'paid')->sum('amount'),
                'refunded'   => (float) Order::sum('refunded_amount'),
                'inFlight'   => (float) Refund::where('state', 'submitted')->sum('amount'),
                'awaiting'   => Refund::where('state', 'requested')->count(),
                'unredeemed' => Order::where('item_type', 'scan_3d')->where('state', 'paid')
                    ->whereDoesntHave('scanJob')->count(),
            ],
            'counts' => Order::selectRaw('state, COUNT(*) c')->groupBy('state')->pluck('c', 'state'),
            'threshold' => (float) config('agentpro.refunds.dual_approval_above'),
        ]);
    }

    /**
     * FR-M11-05.
     *
     * Sends the refund to the provider, unless it is large enough to need a
     * second admin — see App\Actions\IssueRefund for why that is the control
     * rather than a manual step in the provider's own dashboard.
     */
    public function refund(Request $request, Order $order, IssueRefund $refunds)
    {
        $available = $order->refundableAmount();

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.max(0.01, $available)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'amount.max' => 'That is more than is still refundable on this order.',
            'reason.min' => 'Record why — this is what reconciliation reads later.',
        ]);

        try {
            $refund = $refunds->request($order, $request->user(), (float) $data['amount'], $data['reason']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        return back()->with('status', $refund->needsApproval()
            ? 'Refund requested. It needs a second admin to approve it before anything is sent.'
            : 'Refund sent to the provider. It will show as paid back once the money lands.');
    }

    public function approveRefund(Refund $refund, Request $request, IssueRefund $refunds)
    {
        try {
            $refunds->approve($refund, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        return back()->with('status', 'Approved and sent to the provider.');
    }

    public function cancelRefund(Refund $refund, Request $request, IssueRefund $refunds)
    {
        $data = $request->validate([
            'why' => ['required', 'string', 'min:5', 'max:200'],
        ]);

        try {
            $refunds->cancel($refund, $request->user(), $data['why']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        return back()->with('status', 'Refund request cancelled. Nothing was sent.');
    }
}
