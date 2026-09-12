<?php

namespace App\Actions;

use App\Models\MediaAsset;
use App\Models\Property;
use App\Support\Audit;
use App\Support\ImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Photograph upload (FR-M3-01, FR-M3-07, FR-M3-08, SEC-04).
 *
 * The security posture here is: never trust the filename, never trust the
 * client-supplied MIME type, and never store the bytes as received. The file is
 * sniffed by content, decoded, re-encoded, and written under a name this
 * application generated with an extension this application chose.
 */
class StoreListingPhoto
{
    /** What we will actually decode. Sniffed, not taken from the upload. */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private ImageProcessor $images) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Property $property, UploadedFile $file): MediaAsset
    {
        $this->guardCount($property);
        $this->guardContent($file);

        [$image, $width, $height] = $this->images->open($file->getRealPath());

        try {
            $basename = Str::uuid()->toString();
            $relative = 'media/'.$property->uuid;
            $absolute = Storage::disk($this->disk())->path($relative);

            if (! is_dir($absolute)) {
                mkdir($absolute, 0755, true);
            }

            $renditions = $this->images->renditions($image, $absolute, $basename);
            $phash      = $this->images->differenceHash($image);
        } finally {
            imagedestroy($image);
        }

        // Paths are stored relative to the disk so switching the dev disk for
        // S3 in production changes configuration, not data.
        $relativeRenditions = [];
        foreach ($renditions as $key => $path) {
            $relativeRenditions[$key] = $relative.'/'.basename($path);
        }

        $isFirst = $property->media()->where('kind', 'photo')->doesntExist();

        $asset = $property->media()->create([
            'uuid'       => Str::uuid(),
            'kind'       => 'photo',
            'disk'       => $this->disk(),
            'path'       => $relativeRenditions['800'] ?? $relativeRenditions['400'] ?? $relativeRenditions['jpg'],
            'renditions' => $relativeRenditions,
            'width'      => $width,
            'height'     => $height,
            'bytes'      => $file->getSize(),
            'phash'      => $phash,
            'source'     => 'lister',
            'captured_at' => now(),
            // Photographs are visible to the lister immediately; the listing as
            // a whole is what passes through review.
            'moderation_state' => 'approved',
            'is_cover'   => $isFirst,
            'sort_order' => (int) $property->media()->max('sort_order') + 1,
        ]);

        Audit::record('media.uploaded', $property, [], [
            'media_uuid' => $asset->uuid,
            'kind'       => 'photo',
            'phash'      => $phash,
        ]);

        return $asset;
    }

    private function guardCount(Property $property): void
    {
        $max = (int) config('agentpro.media.max_photos');

        if ($property->media()->where('kind', 'photo')->count() >= $max) {
            throw ValidationException::withMessages([
                'photos' => "A listing can carry at most {$max} photographs.",
            ]);
        }
    }

    /**
     * SEC-04: content sniffing, not the extension and not the browser-supplied
     * type. A file called cover.jpg that is really a PHP script must not get
     * past this.
     */
    private function guardContent(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['photos' => 'That upload did not complete.']);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file->getRealPath());

        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            throw ValidationException::withMessages([
                'photos' => 'Photographs must be JPEG, PNG or WebP. That file is '.($mime ?: 'unrecognised').'.',
            ]);
        }

        // getimagesize is a second, independent check: a file can carry a valid
        // image MIME signature in its first bytes and still not decode.
        if (@getimagesize($file->getRealPath()) === false) {
            throw ValidationException::withMessages(['photos' => 'That file is not a usable image.']);
        }
    }

    private function disk(): string
    {
        return config('agentpro.media.disk', 'public');
    }
}
