<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Audit;
use Illuminate\Http\Request;

/** FR-M11-04, FR-M11-05: order history and refunds. */
class OrderAdminController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.orders', [
            'orders' => Order::query()
                ->when($request->filled('state'), fn ($q) => $q->where('state', $request->query('state')))
                ->with(['user:id,name,email', 'property:id,uuid,title', 'scanJob'])
                ->orderByDesc('id')
                ->paginate(30)
                ->withQueryString(),
            'totals' => [
                'paid'       => (float) Order::where('state', 'paid')->sum('amount'),
                'refunded'   => (float) Order::sum('refunded_amount'),
                'unredeemed' => Order::where('item_type', 'scan_3d')->where('state', 'paid')
                    ->whereDoesntHave('scanJob')->count(),
            ],
            'counts' => Order::selectRaw('state, COUNT(*) c')->groupBy('state')->pluck('c', 'state'),
        ]);
    }

    /**
     * FR-M11-05.
     *
     * Records the refund; it does not move money. Disbursement happens in
     * Paystack and is reconciled against this record — the platform is
     * deliberately not given the authority to push funds, so a compromised
     * admin session cannot drain the account.
     */
    public function refund(Request $request, Order $order)
    {
        $outstanding = (float) $order->amount - (float) $order->refunded_amount;

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.max(0.01, $outstanding)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'amount.max' => 'That is more than the amount still outstanding on this order.',
            'reason.min' => 'Record why — this is what reconciliation reads later.',
        ]);

        $before = ['state' => $order->state, 'refunded_amount' => (float) $order->refunded_amount];
        $total  = (float) $order->refunded_amount + (float) $data['amount'];

        $order->update([
            'refunded_amount' => $total,
            'refund_reason'   => $data['reason'],
            'state'           => $total >= (float) $order->amount ? 'refunded' : 'partially_refunded',
        ]);

        Audit::record('order.refunded', $order, $before, [
            'refunded_amount' => $total,
            'reason'          => $data['reason'],
        ]);

        return back()->with('status', 'Refund recorded. Issue it in Paystack to complete it.');
    }
}
