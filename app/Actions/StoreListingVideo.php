<?php

namespace App\Actions;

use App\Jobs\TranscodeVideo;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Walkthrough video upload (FR-M3-09, FR-M3-11, FR-M3-12).
 *
 * Two rules distinguish video from photographs:
 *
 *  - It is created 'pending', not 'approved'. Video is the easiest place to get
 *    content past a reviewer who is scanning thumbnails, so it is moderated in
 *    its own right (FR-M3-12) and does not appear on the listing until cleared.
 *  - Nothing is processed in the web request. Transcoding a three-minute clip
 *    would hold a PHP-FPM worker for minutes; it goes on the queue.
 *
 * One video per listing, deliberately. A seeker will watch one walkthrough; a
 * gallery of five is an upload burden for the lister and a data cost for
 * everyone else.
 */
class StoreListingVideo
{
    private const ALLOWED_MIME = ['video/mp4', 'video/quicktime', 'video/x-matroska', 'video/webm'];

    /**
     * @throws ValidationException
     */
    public function __invoke(Property $property, UploadedFile $file): MediaAsset
    {
        $this->guardContent($file);

        $existing = $property->media()->where('kind', 'video')->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'video' => 'This listing already has a walkthrough video. Remove it before uploading another.',
            ]);
        }

        $relative = 'media/'.$property->uuid;
        $basename = Str::uuid()->toString();
        $stored   = $file->storeAs($relative, $basename.'.'.$this->extensionFor($file), $this->disk());

        $asset = $property->media()->create([
            'uuid'   => Str::uuid(),
            'kind'   => 'video',
            'disk'   => $this->disk(),
            'path'   => $stored,
            'bytes'  => $file->getSize(),
            'source' => 'lister',
            'captured_at' => now(),
            // FR-M3-12
            'moderation_state' => 'pending',
            'sort_order' => (int) $property->media()->max('sort_order') + 1,
        ]);

        Audit::record('media.uploaded', $property, [], [
            'media_uuid' => $asset->uuid,
            'kind'       => 'video',
            'bytes'      => $file->getSize(),
        ]);

        // FR-M3-11: renditions and the poster frame are produced off the request.
        TranscodeVideo::dispatch($asset->id);

        return $asset;
    }

    private function guardContent(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['video' => 'That upload did not complete.']);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file->getRealPath());

        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            throw ValidationException::withMessages([
                'video' => 'Video must be MP4, MOV, WebM or MKV. That file is '.($mime ?: 'unrecognised').'.',
            ]);
        }
    }

    /** Extension is derived from the sniffed type, never from the upload name. */
    private function extensionFor(UploadedFile $file): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return match ($finfo->file($file->getRealPath())) {
            'video/quicktime'  => 'mov',
            'video/webm'       => 'webm',
            'video/x-matroska' => 'mkv',
            default            => 'mp4',
        };
    }

    private function disk(): string
    {
        return config('agentpro.media.disk', 'public');
    }
}
