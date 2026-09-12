<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Video renditions and poster frame (FR-M3-11).
 *
 * Produces 720p and 480p so a seeker on a metered connection is not sent a
 * 1080p file (NFR-02), plus a poster frame — without one the media viewer has
 * nothing to show at rest and the gate in FR-M3-09 cannot render.
 *
 * FFmpeg is a real external dependency. When it is absent the job does not
 * pretend to succeed and does not silently drop the upload: the asset stays
 * pending with a recorded reason, so an operator can see exactly why a video
 * never went live. Failing loudly here is the point — a video that quietly
 * disappears is a support ticket the lister opens a week later.
 */
class TranscodeVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public int $mediaAssetId) {}

    public function handle(): void
    {
        $asset = MediaAsset::find($this->mediaAssetId);

        if (! $asset || $asset->kind->value !== 'video') {
            return;
        }

        $ffmpeg = $this->binary('ffmpeg');

        if ($ffmpeg === null) {
            $this->recordUnavailable($asset);

            return;
        }

        $disk     = Storage::disk($asset->disk);
        $source   = $disk->path($asset->path);
        $dir      = dirname($source);
        $base     = pathinfo($asset->path, PATHINFO_FILENAME);
        $relative = dirname($asset->path);

        $renditions = [];

        foreach (['720' => 1280, '480' => 854] as $label => $width) {
            $target = $dir.'/'.$base.'-'.$label.'.mp4';

            $this->run([
                $ffmpeg, '-y', '-i', $source,
                // -2 keeps the height even, which H.264 requires.
                '-vf', 'scale='.$width.':-2',
                '-c:v', 'libx264', '-preset', 'medium', '-crf', '23',
                '-c:a', 'aac', '-b:a', '128k',
                // Puts the index at the front so playback can start before the
                // whole file has arrived.
                '-movflags', '+faststart',
                $target,
            ]);

            $renditions[$label.'p'] = $relative.'/'.basename($target);
        }

        $poster = $dir.'/'.$base.'-poster.jpg';

        $this->run([
            $ffmpeg, '-y', '-i', $source,
            '-ss', '00:00:02', '-vframes', '1',
            '-vf', 'scale=1280:-2',
            $poster,
        ]);

        $asset->update([
            'renditions'       => $renditions,
            'poster_path'      => $relative.'/'.basename($poster),
            'duration_seconds' => $this->duration($source),
            // Still pending: transcoding is not approval (FR-M3-12).
            'moderation_state' => 'pending',
        ]);

        Log::info('media.video.transcoded', [
            'media_asset_id' => $asset->id,
            'renditions'     => array_keys($renditions),
        ]);
    }

    /** Duration via ffprobe, so the viewer can show it before playback starts. */
    private function duration(string $source): ?int
    {
        $ffprobe = $this->binary('ffprobe');

        if ($ffprobe === null) {
            return null;
        }

        $process = new Process([
            $ffprobe, '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $source,
        ]);

        $process->run();

        return $process->isSuccessful()
            ? (int) round((float) trim($process->getOutput()))
            : null;
    }

    private function run(array $command): void
    {
        $process = new Process($command);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    /** Configured path first, then whatever is on PATH. */
    private function binary(string $name): ?string
    {
        $configured = config('agentpro.media.'.$name.'_path');

        if ($configured && is_executable($configured)) {
            return $configured;
        }

        $probe = new Process([$name, '-version']);
        $probe->run();

        return $probe->isSuccessful() ? $name : null;
    }

    private function recordUnavailable(MediaAsset $asset): void
    {
        Log::warning('media.video.transcode_unavailable', [
            'media_asset_id' => $asset->id,
            'reason'         => 'ffmpeg not found on PATH and agentpro.media.ffmpeg_path is not set',
        ]);

        $asset->update(['moderation_state' => 'pending']);

        // Nothing is deleted. The original upload is intact and the job can be
        // replayed once FFmpeg is installed.
    }
}
