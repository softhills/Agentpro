<?php

namespace App\Console\Commands;

use App\Actions\EraseAccount;
use App\Models\DataRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Carry out erasures whose cooling-off has ended, and clear exports nobody
 * collected (FR-M1-09).
 *
 * The cooling-off period is the control, and a control that depends on somebody
 * remembering to press a button is not one. So this runs on a schedule and the
 * request row is the queue.
 *
 * It clears expired export files in the same pass, which matters more than it
 * looks: an uncollected export is a complete copy of a person's account sitting
 * on a disk. Building them and never removing them would mean the feature meant
 * to honour a privacy right slowly became the largest pile of personal data on
 * the server.
 */
class RunDataRequests extends Command
{
    protected $signature = 'agentpro:run-data-requests';

    protected $description = 'Execute due account erasures and remove expired data exports';

    public function handle(EraseAccount $erasure): int
    {
        $this->expireExports();
        $this->runErasures($erasure);

        return self::SUCCESS;
    }

    private function runErasures(EraseAccount $erasure): void
    {
        $due = DataRequest::where('kind', 'erasure')
            ->where('state', 'pending')
            ->where('executes_at', '<=', now())
            ->orderBy('id')
            ->get();

        foreach ($due as $request) {
            /*
             * One failure must not stop the rest. An erasure that throws is a
             * bug we have to fix, but leaving every other person's request
             * unhonoured behind it would turn one bug into a compliance
             * failure across the whole queue.
             */
            try {
                $result = $erasure->execute($request);

                $this->line(match ($result->state) {
                    'completed' => 'Erased account #'.$request->user_id.'.',
                    'refused'   => 'Refused #'.$request->user_id.': '.$result->note,
                    default     => 'Left #'.$request->user_id.' at '.$result->state.'.',
                });
            } catch (\Throwable $e) {
                $request->update([
                    'state' => 'failed',
                    'note'  => Str::limit($e->getMessage(), 200),
                ]);

                $this->error('Erasure for #'.$request->user_id.' failed: '.$e->getMessage());
            }
        }

        $this->info($due->count().' erasure '.Str::plural('request', $due->count()).' processed.');
    }

    private function expireExports(): void
    {
        $stale = DataRequest::where('kind', 'export')
            ->whereNotNull('file_path')
            ->where('expires_at', '<=', now())
            ->get();

        $disk = Storage::disk((string) config('agentpro.privacy.export_disk'));

        foreach ($stale as $request) {
            $disk->delete($request->file_path);

            $request->update([
                'file_path' => null,
                // A file that was downloaded and then aged out is a completed
                // request, not an expired one. Overwriting that would lose the
                // record of having answered within the deadline.
                'state'     => $request->state === 'completed' ? 'completed' : 'expired',
            ]);
        }

        $this->info($stale->count().' expired '.Str::plural('export', $stale->count()).' removed.');
    }
}
