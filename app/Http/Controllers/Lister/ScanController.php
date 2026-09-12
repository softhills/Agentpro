<?php

namespace App\Http\Controllers\Lister;

use App\Actions\RequestScanUpgrade;
use App\Actions\ScheduleScan;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Property;
use App\Models\TechnicianSlot;
use App\Services\Payments\PaymentGateway;
use App\Support\Money;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    /** FR-M4-01 / FR-M4-03: the offer, or a clear account of why not. */
    public function show(Property $property, RequestScanUpgrade $request)
    {
        $this->authorize('update', $property);

        return view('scan.offer', [
            'property' => $property,
            'reasons'  => $request->ineligibility($property),
            'price'    => Money::naira(config('agentpro.prices.scan_3d')),
        ]);
    }

    /** Creates the order and hands off to the gateway. */
    public function checkout(
        Request $httpRequest,
        Property $property,
        RequestScanUpgrade $request,
        PaymentGateway $gateway,
    ) {
        $this->authorize('update', $property);

        // Eligibility is re-checked here, not trusted from the page that
        // rendered the button.
        $order = $request($property, $httpRequest->user());

        $checkout = $gateway->initialise($order, route('scan.return', $order));

        $order->update(['paystack_reference' => $checkout->reference]);

        return redirect()->away($checkout->redirectUrl);
    }

    /**
     * Where the payer lands coming back from the gateway.
     *
     * Deliberately confirms nothing. The browser's return is not evidence of
     * payment (FR-M4-04) — it only decides which message to show while the
     * webhook does the real work.
     */
    public function returned(Order $order)
    {
        abort_unless($order->user_id === request()->user()->id, 404);

        return $order->isPaid()
            ? redirect()->route('scan.schedule', $order)
            : view('scan.pending', ['order' => $order]);
    }

    /** FR-M4-05: real availability, in the property's own area. */
    public function schedule(Order $order, ScheduleScan $scheduler)
    {
        abort_unless($order->user_id === request()->user()->id, 404);

        if (! $order->isPaid()) {
            return view('scan.pending', ['order' => $order]);
        }

        if ($job = $order->scanJob) {
            return view('scan.booked', ['order' => $order, 'job' => $job]);
        }

        return view('scan.schedule', [
            'order' => $order,
            'slots' => $scheduler->availableSlots($order),
        ]);
    }

    public function book(Request $httpRequest, Order $order, ScheduleScan $scheduler)
    {
        abort_unless($order->user_id === $httpRequest->user()->id, 404);

        $data = $httpRequest->validate([
            'slot_id' => ['required', 'exists:technician_slots,id'],
        ]);

        $job = $scheduler($order, TechnicianSlot::findOrFail($data['slot_id']));

        return redirect()
            ->route('scan.schedule', $order)
            ->with('status', 'Booked for '.$job->scheduled_for->format('l j F, g:ia').'.');
    }
}
