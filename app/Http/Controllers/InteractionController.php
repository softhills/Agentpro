<?php

namespace App\Http\Controllers;

use App\Models\Interaction;
use App\Models\Property;
use App\Support\Analytics;
use App\Support\Audit;
use App\Support\PhoneNumber;
use App\Support\Vocab;
use Illuminate\Http\Request;

/**
 * Seeker interactions (FR-M9-01, FR-M9-02, FR-M9-10, FR-M6-07, FR-M6-08).
 *
 * These are also what define the audience for listing alerts (FR-M9-04), so an
 * interaction recorded here is a standing statement of interest, not analytics.
 */
class InteractionController extends Controller
{
    /** FR-M9-01: save, and un-save. */
    public function toggleSave(Request $request, Property $property)
    {
        $existing = $this->find($request, $property, 'save');

        if ($existing) {
            $existing->delete();

            return back()->with('status', 'Removed from your saved listings.');
        }

        $this->record($request, $property, 'save');

        // Saving a hidden listing is a change of mind; drop the hide so the
        // two do not contradict each other.
        $this->find($request, $property, 'hide')?->delete();

        return back()->with('status', 'Saved. We will tell you if anything changes.');
    }

    /** FR-M9-10: hide, so it stops appearing in results. */
    public function toggleHide(Request $request, Property $property)
    {
        $existing = $this->find($request, $property, 'hide');

        if ($existing) {
            $existing->delete();

            return back()->with('status', 'This listing will show in your results again.');
        }

        $this->record($request, $property, 'hide');

        return back()->with('status', 'Hidden from your results.');
    }

    /** FR-M6-08 */
    public function rate(Request $request, Property $property)
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'note'   => ['nullable', 'string', 'max:1000'],
        ]);

        $this->record($request, $property, 'rate', $data);

        return back()->with('status', 'Thank you — your rating helps other seekers.');
    }

    /** FR-M6-07: report, into the moderation queue. */
    public function report(Request $request, Property $property)
    {
        $data = $request->validate([
            'reason_code' => ['required', 'in:'.implode(',', array_keys(Vocab::REPORT_REASONS))],
            'note'        => ['nullable', 'string', 'max:1000'],
        ]);

        $this->record($request, $property, 'report', $data);

        Audit::record('listing.reported', $property, [], ['reason_code' => $data['reason_code']]);

        return back()->with(
            'status',
            'Reported. We review fraud reports within '.config('agentpro.sla.fraud_report_hours').' working hours.'
        );
    }

    /**
     * FR-M9-02: every contact initiation is logged against the listing.
     *
     * Recorded before the redirect so the count is right even when the seeker
     * abandons the call — the lister is paying for exposure, and an enquiry
     * that was started is still signal.
     */
    public function contact(Request $request, Property $property)
    {
        $data = $request->validate([
            'mode'   => ['required', 'in:phone,whatsapp,email'],
            // A viewing request is an email contact with a different subject.
            // The real thing — in-platform scheduling against technician
            // availability — is FR-M9-12 and deferred to R2; this at least
            // starts the conversation rather than being a button that lies.
            'intent' => ['nullable', 'in:enquiry,viewing'],
        ]);

        $this->record($request, $property, 'contact', ['contact_mode' => $data['mode']]);

        /*
         * Recorded twice, and not by mistake. The interaction above is the
         * standing relationship — unique per person and listing, which is what
         * makes this seeker an audience for alerts about it. That uniqueness is
         * also why it cannot answer FR-M13-01: someone who rings and then
         * messages overwrites their own row, so the table knows the latest mode
         * rather than how many initiations there were by which route.
         */
        Analytics::record(Analytics::CONTACT, $property, context: $data['mode']);

        /*
         * The lister's details are handed back here rather than rendered into
         * the listing page.
         *
         * Two reasons. FR-M1-03 requires an account to contact a lister, and a
         * phone number sitting in the HTML is available to anyone who views
         * source — account or not. And a marketplace's contact details are the
         * single most scrapeable thing it holds (SEC-10): put them behind a
         * POST and a bot has to be signed in and leave a row in `interactions`
         * for every one it takes.
         *
         * It also means the initiation is logged before the channel opens,
         * which is what makes the count right even when the seeker abandons
         * the call.
         */
        $details = $this->channelFor($property, $data['mode'], $data['intent'] ?? 'enquiry');

        if ($request->expectsJson()) {
            return response()->json(['recorded' => true] + $details);
        }

        return back()->with('contact', $details)->withFragment('contact');
    }

    /**
     * How to actually reach this lister, for one mode.
     *
     * Returns the number or address written out as well as a link. On a desktop
     * a `tel:` does nothing useful, and "click here to call" with no number
     * visible is a dead end — the seeker wants to read it off the screen and
     * dial it on their phone.
     *
     * @return array<string,?string>
     */
    private function channelFor(Property $property, string $mode, string $intent): array
    {
        $lister = $property->lister()->first();
        $subject = ($intent === 'viewing' ? 'Viewing request' : 'Enquiry').' — '.$property->title;

        $body = $intent === 'viewing'
            ? "Hello,\n\nI would like to arrange a viewing of ".$property->title
                .' at '.$property->address_line.".\n\nWhen are you available?"
            : "Hello,\n\nI am interested in ".$property->title
                .' at '.$property->address_line.".\n\nIs it still available?";

        return match ($mode) {
            'phone' => [
                'mode'  => 'phone',
                'label' => 'Call '.$lister->name,
                'value' => PhoneNumber::national($lister->phone),
                'href'  => $lister->phone ? 'tel:'.PhoneNumber::e164($lister->phone) : null,
            ],
            'whatsapp' => [
                'mode'  => 'whatsapp',
                'label' => 'WhatsApp '.$lister->name,
                'value' => PhoneNumber::national($lister->phone),
                // wa.me wants digits with no plus and no spaces.
                'href'  => PhoneNumber::msisdn($lister->phone)
                    ? 'https://wa.me/'.PhoneNumber::msisdn($lister->phone).'?text='.rawurlencode($body)
                    : null,
            ],
            default => [
                'mode'  => 'email',
                'label' => ($intent === 'viewing' ? 'Request a viewing from ' : 'Email ').$lister->name,
                'value' => $lister->email,
                'href'  => 'mailto:'.$lister->email
                    .'?subject='.rawurlencode($subject).'&body='.rawurlencode($body),
            ],
        };
    }

    private function find(Request $request, Property $property, string $kind): ?Interaction
    {
        return Interaction::where('user_id', $request->user()->id)
            ->where('property_id', $property->id)
            ->where('kind', $kind)
            ->first();
    }

    private function record(Request $request, Property $property, string $kind, array $extra = []): Interaction
    {
        // Unique on (user, property, kind), so repeating an action updates
        // rather than accumulating duplicates.
        return Interaction::updateOrCreate(
            [
                'user_id'     => $request->user()->id,
                'property_id' => $property->id,
                'kind'        => $kind,
            ],
            $extra
        );
    }
}
