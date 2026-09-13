<?php

namespace App\Jobs;

use App\Actions\ExportAccountData;
use App\Models\DataRequest;
use App\Notifications\AccountDataReady;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Build a data export off the request thread (FR-M1-09).
 *
 * Queued for two reasons, and the second is the real one. It reads seventeen
 * tables, which is slow enough to be rude in a web request for a lister with
 * hundreds of listings — but more importantly it means the person is told the
 * file is ready through their registered contact details, rather than handed it
 * in the same browser tab. An export is a complete copy of everything we hold
 * about somebody, so a stolen session should not be able to walk off with one
 * without the real owner ever hearing about it.
 */
class BuildDataExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $dataRequestId) {}

    public function handle(ExportAccountData $export): void
    {
        $request = DataRequest::find($this->dataRequestId);

        // Cancelled while it sat in the queue, or already built by a retry.
        if (! $request || $request->state !== 'pending' || $request->kind !== 'export') {
            return;
        }

        $export($request);

        $request->user?->notify(new AccountDataReady($request));
    }

    /**
     * A failed export has to say so on the screen.
     *
     * Otherwise it sits at "being prepared" forever, and the person waits for a
     * file that is never coming — which reads as the platform ignoring a
     * statutory request rather than as a bug.
     */
    public function failed(\Throwable $e): void
    {
        DataRequest::where('id', $this->dataRequestId)
            ->where('state', 'pending')
            ->update([
                'state' => 'failed',
                'note'  => Str::limit('We could not build your file: '.$e->getMessage(), 200),
            ]);
    }
}
