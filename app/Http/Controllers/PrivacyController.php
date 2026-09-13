<?php

namespace App\Http\Controllers;

use App\Actions\EraseAccount;
use App\Jobs\BuildDataExport;
use App\Models\DataRequest;
use App\Support\Audit;
use App\Support\PersonalData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Your data, and getting rid of it (FR-M1-09, NDPA 2023 ss. 34 and 38).
 *
 * Every write here asks for the password again. Not theatre: an export is a
 * complete copy of everything we hold about somebody and an erasure cannot be
 * undone, so these are the two requests on the platform where a borrowed
 * session — a shared laptop, an unlocked phone — does the most damage. A
 * password prompt is the cheapest thing that distinguishes the account holder
 * from whoever is sitting at their desk.
 */
class PrivacyController extends Controller
{
    public function __construct(private EraseAccount $erasure) {}

    public function index(Request $request)
    {
        $user = $request->user();

        return view('account.privacy', [
            'requests' => DataRequest::where('user_id', $user->id)
                ->orderByDesc('id')->limit(20)->get(),
            'openExport'  => $this->openRequest($user->id, 'export'),
            'openErasure' => $this->openRequest($user->id, 'erasure'),
            // Shown before anyone commits to anything, and shown in full —
            // including what we keep and why, which is the part a privacy
            // screen usually leaves out.
            'disposal'    => PersonalData::summary(),
            'blockers'    => $this->erasure->blockers($user),
            'graceHours'  => PersonalData::erasureGraceHours(),
            'expiryHours' => PersonalData::exportExpiryHours(),
        ]);
    }

    /** Section 38: a copy, in a format something else can read. */
    public function export(Request $request)
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $user = $request->user();

        if ($this->openRequest($user->id, 'export')) {
            return back()->with('status', 'A copy is already being prepared for you.');
        }

        $data = DataRequest::create([
            'uuid'       => (string) Str::uuid(),
            'user_id'    => $user->id,
            'kind'       => 'export',
            'state'      => 'pending',
            'ip'         => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        Audit::record('data_request.export_requested', $data, [], [], $user->id);

        BuildDataExport::dispatch($data->id);

        return back()->with('status',
            'We are putting your file together. You will get a message when it is ready to download.');
    }

    /**
     * Hand over the file.
     *
     * Behind the session rather than a signed link, which is why the message
     * announcing it carries no download URL: a forwarded email should not be a
     * copy of somebody's whole account.
     */
    public function download(Request $request, DataRequest $dataRequest)
    {
        $this->mustOwn($request, $dataRequest);

        abort_unless($dataRequest->isDownloadable(), 410, 'That file is no longer available.');

        $disk = Storage::disk((string) config('agentpro.privacy.export_disk'));

        // The row says ready and the file is gone — a disk wiped between
        // deploys, most likely. Saying so beats a 500.
        abort_unless($disk->exists($dataRequest->file_path), 410, 'That file is no longer available.');

        // First download completes the request: the Act is satisfied when the
        // copy reaches the person, and that is the timestamp a regulator would
        // ask about. The file stays until it expires, because "I downloaded it
        // on the train and lost it" is not a reason to make somebody ask again.
        $dataRequest->update([
            'downloaded_at' => $dataRequest->downloaded_at ?? now(),
            'state'         => 'completed',
            'completed_at'  => $dataRequest->completed_at ?? now(),
        ]);

        Audit::record('data_request.downloaded', $dataRequest, [], [], $request->user()->id);

        return $disk->download(
            $dataRequest->file_path,
            'agentpro-data-'.now()->format('Y-m-d').'.json',
            ['Content-Type' => 'application/json']
        );
    }

    /** Section 34: erasure, after a cooling-off period. */
    public function erase(Request $request)
    {
        $request->validate([
            'password' => ['required', 'current_password'],
            // Typed, not ticked. A checkbox next to a destructive button is the
            // thing people click without reading; a word they have to type is
            // the thing they stop at.
            'confirm'  => ['required', 'in:CLOSE MY ACCOUNT'],
        ], [
            'confirm.in' => 'Type CLOSE MY ACCOUNT exactly, in capitals, to confirm.',
        ]);

        try {
            $this->erasure->request($request->user(), $request->ip(), $request->userAgent());
        } catch (RuntimeException $e) {
            return back()->withErrors(['confirm' => $e->getMessage()]);
        }

        return back()->with('status',
            'Your account is scheduled to be closed. We have sent you a message about it — '
            .'if it was not you who asked, come back here and stop it.');
    }

    /** Stop a scheduled erasure, or an export that is no longer wanted. */
    public function cancel(Request $request, DataRequest $dataRequest)
    {
        $this->mustOwn($request, $dataRequest);

        try {
            $this->erasure->cancel($dataRequest, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['confirm' => $e->getMessage()]);
        }

        /*
         * Cancelling asks for nothing — no password, no confirmation. It is the
         * escape hatch in an account-takeover, and every second of friction on
         * it is a second the attacker keeps. An attacker who cancels their own
         * erasure has achieved nothing, so there is no downside to making it
         * free.
         */
        if ($dataRequest->kind === 'erasure') {
            return back()->with('status',
                'Stopped — nothing has been deleted. If you did not ask for this, change your '
                .'password now: somebody else was signed in as you.');
        }

        return back()->with('status', 'Cancelled.');
    }

    // ------------------------------------------------------------------ internals

    /**
     * A request still worth showing as in progress.
     *
     * The expiry check is not tidiness. Without it an export that aged out
     * while still marked `ready` counts as open forever: the screen says "we
     * are putting your file together" about a file that was deleted, and asking
     * again is refused because one is supposedly already coming. The scheduled
     * command does eventually flip it to `expired`, but "your statutory request
     * is stuck until the next hourly tick" is not an acceptable answer, and a
     * screen that describes a deleted file as being prepared is worse.
     */
    private function openRequest(int $userId, string $kind): ?DataRequest
    {
        return DataRequest::where('user_id', $userId)
            ->where('kind', $kind)
            ->where(function ($query) {
                $query->where('state', 'pending')
                    ->orWhere(fn ($ready) => $ready->where('state', 'ready')->where('expires_at', '>', now()));
            })
            ->latest('id')
            ->first();
    }

    /**
     * 404, not 403.
     *
     * A request id that exists but belongs to somebody else should be
     * indistinguishable from one that does not exist — otherwise the difference
     * between the two responses is a way to count other people's requests.
     */
    private function mustOwn(Request $request, DataRequest $dataRequest): void
    {
        abort_unless($dataRequest->user_id === $request->user()->id, 404);
    }
}
