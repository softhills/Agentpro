<?php

namespace App\Support;

use RuntimeException;

/**
 * Server-side image processing (FR-M3-07, SEC-04).
 *
 * Every uploaded image is decoded and re-encoded here rather than being stored
 * as received. That is the security control as much as the performance one: a
 * file that survives a full decode/encode round trip through GD cannot still be
 * carrying a polyglot payload in its metadata, and EXIF — including the GPS tag
 * that would otherwise publish a seller's home coordinates — does not survive
 * re-encoding at all.
 *
 * Orientation is the one EXIF field read before it is discarded, because a phone
 * photograph that is not rotated first will be re-encoded permanently sideways.
 */
class ImageProcessor
{
    /** Widths generated for the responsive set. */
    public const WIDTHS = [400, 800, 1600];

    /** JPEG fallback width, for clients that still cannot take WebP. */
    public const FALLBACK_WIDTH = 1200;

    private const WEBP_QUALITY = 82;
    private const JPEG_QUALITY = 84;

    /**
     * Decode, orient, and hand back a GD image plus its true dimensions.
     *
     * @return array{0:\GdImage,1:int,2:int}
     */
    public function open(string $path): array
    {
        $info = @getimagesize($path);

        if ($info === false) {
            throw new RuntimeException('Not a readable image.');
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default        => null,
        };

        if (! $image) {
            throw new RuntimeException('Unsupported image format.');
        }

        $image = $this->applyOrientation($image, $path, $info[2]);

        return [$image, imagesx($image), imagesy($image)];
    }

    /**
     * Write the responsive set. Returns rendition width => relative path, with a
     * 'jpg' entry for the fallback.
     *
     * @return array<string,string>
     */
    public function renditions(\GdImage $source, string $destinationDir, string $basename): array
    {
        $written = [];
        $sourceWidth = imagesx($source);

        foreach (self::WIDTHS as $width) {
            // Never upscale — a 600px photograph blown up to 1600 costs bytes
            // and delivers nothing.
            if ($width > $sourceWidth && $width !== self::WIDTHS[0]) {
                continue;
            }

            $resized = $this->resize($source, min($width, $sourceWidth));
            $path    = $destinationDir.'/'.$basename.'-'.$width.'.webp';

            imagewebp($resized, $path, self::WEBP_QUALITY);
            imagedestroy($resized);

            $written[(string) $width] = $path;
        }

        $fallback = $this->resize($source, min(self::FALLBACK_WIDTH, $sourceWidth));
        $jpegPath = $destinationDir.'/'.$basename.'.jpg';
        imagejpeg($fallback, $jpegPath, self::JPEG_QUALITY);
        imagedestroy($fallback);

        $written['jpg'] = $jpegPath;

        return $written;
    }

    /**
     * Difference hash (FR-M3-08).
     *
     * 64 bits derived from whether each pixel is brighter than its right-hand
     * neighbour, at 9x8 greyscale. Resolution, re-compression and modest crops
     * barely move it, which is exactly what is wanted: the question is not "is
     * this the same file" but "is this the same photograph, reappearing on
     * someone else's listing".
     */
    public function differenceHash(\GdImage $source): string
    {
        $w = 9;
        $h = 8;

        $small = imagecreatetruecolor($w, $h);
        imagecopyresampled($small, $source, 0, 0, 0, 0, $w, $h, imagesx($source), imagesy($source));

        $bits = '';

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w - 1; $x++) {
                $bits .= $this->luma($small, $x, $y) > $this->luma($small, $x + 1, $y) ? '1' : '0';
            }
        }

        imagedestroy($small);

        // 64 bits -> 16 hex characters.
        $hex = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex(bindec($nibble));
        }

        return $hex;
    }

    /** Hamming distance between two hashes. 0 is identical; under ~10 is a match. */
    public static function hashDistance(string $a, string $b): int
    {
        if (strlen($a) !== strlen($b)) {
            return PHP_INT_MAX;
        }

        $distance = 0;

        for ($i = 0, $len = strlen($a); $i < $len; $i++) {
            $distance += substr_count(
                decbin(hexdec($a[$i]) ^ hexdec($b[$i])),
                '1'
            );
        }

        return $distance;
    }

    private function resize(\GdImage $source, int $width): \GdImage
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        $height = max(1, (int) round($sh * ($width / $sw)));

        $out = imagecreatetruecolor($width, $height);

        // Flatten transparency onto white rather than losing it to black, which
        // is what an untouched truecolor canvas would give a PNG with alpha.
        $white = imagecolorallocate($out, 255, 255, 255);
        imagefilledrectangle($out, 0, 0, $width, $height, $white);

        imagecopyresampled($out, $source, 0, 0, 0, 0, $width, $height, $sw, $sh);

        return $out;
    }

    private function applyOrientation(\GdImage $image, string $path, int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = $exif['Orientation'] ?? 1;

        $rotated = match ($orientation) {
            3       => imagerotate($image, 180, 0),
            6       => imagerotate($image, -90, 0),
            8       => imagerotate($image, 90, 0),
            default => null,
        };

        if ($rotated) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    private function luma(\GdImage $image, int $x, int $y): float
    {
        $rgb = imagecolorat($image, $x, $y);

        return 0.299 * (($rgb >> 16) & 0xFF)
             + 0.587 * (($rgb >> 8) & 0xFF)
             + 0.114 * ($rgb & 0xFF);
    }
}
