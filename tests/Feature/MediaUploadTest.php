<?php

namespace Tests\Feature;

use App\Actions\StoreListingPhoto;
use App\Enums\LifecycleState;
use App\Models\Area;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Support\ImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Media upload (M3).
 *
 * These tests push real image bytes through the real pipeline rather than
 * faking the filesystem, because the things worth protecting here — that a
 * disguised file is rejected, that EXIF does not survive, that the responsive
 * set is actually written — are properties of the encoder, not of the model.
 */
class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['agentpro.media.disk' => 'public']);
    }

    private function lister(): User
    {
        return User::forceCreate([
            'uuid' => Str::uuid(),
            'name' => 'Tunde Adeyemi',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'x',
            'category' => 'sellers_agent',
            'verification_state' => 'verified',
            'verified_at' => now(),
        ]);
    }

    private function listing(?User $lister = null): Property
    {
        $lister ??= $this->lister();

        $area = Area::firstOrCreate(['slug' => 'lekki-phase-1'], [
            'name' => 'Lekki Phase 1', 'city' => 'Lagos', 'state' => 'Lagos',
            'is_scan_coverage' => true,
        ]);

        $property = Property::create([
            'uuid' => Str::uuid(),
            'lister_id' => $lister->id,
            'area_id' => $area->id,
            'title' => '3-Bed Apartment, Ikate',
            'slug' => 'ikate-'.Str::lower(Str::random(6)),
            'listing_type' => 'apartment',
            'intent' => 'rent',
            'build_status' => 'fully_built',
            'address_line' => 'Off Ikate Elegushi Road',
            'city' => 'Lagos', 'state' => 'Lagos',
            'lat' => 6.4441, 'lng' => 3.4795,
            'location' => DB::raw("ST_GeomFromText('POINT(3.4795 6.4441)')"),
            'lifecycle_state' => LifecycleState::Draft->value,
        ]);

        Unit::create([
            'uuid' => Str::uuid(), 'property_id' => $property->id, 'is_primary' => true,
            'price' => 7500000, 'price_period' => 'year', 'bedrooms' => 3,
        ]);

        return $property;
    }

    /**
     * A genuine JPEG with real pixel content.
     *
     * The pattern is laid out in proportion to the canvas, not in fixed pixels,
     * so scaling the arguments produces the *same photograph at another size*
     * rather than a different photograph — which is what the hash test needs to
     * be asking about.
     */
    private function realJpeg(int $w = 1400, int $h = 1050, int $seed = 1): UploadedFile
    {
        $image = imagecreatetruecolor($w, $h);

        // A seeded 12x9 block mosaic. Two properties matter:
        //
        //  - The layout is proportional, so scaling the arguments yields the
        //    same photograph at another size rather than a different one.
        //  - Brightness varies non-monotonically. An image whose luma simply
        //    increases left to right is degenerate for a difference hash —
        //    every comparison is false and the hash is all zeros — which would
        //    make any comparison between two such images pass for free.
        mt_srand($seed);

        $cols = 12;
        $rows = 9;
        $cw   = (int) ceil($w / $cols);
        $ch   = (int) ceil($h / $rows);

        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $colour = imagecolorallocate(
                    $image,
                    mt_rand(0, 255),
                    mt_rand(0, 255),
                    mt_rand(0, 255)
                );
                imagefilledrectangle($image, $c * $cw, $r * $ch, ($c + 1) * $cw - 1, ($r + 1) * $ch - 1, $colour);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'img').'.jpg';
        imagejpeg($image, $path, 92);
        imagedestroy($image);

        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
    }

    /** Photographs in upload order. The relation carries its own ordering, so a
     *  chained orderByDesc would be appended, not applied — hence a fresh query. */
    private function photos(Property $property)
    {
        return \App\Models\MediaAsset::where('property_id', $property->id)
            ->where('kind', 'photo')
            ->orderBy('sort_order')
            ->get();
    }

    public function test_a_photograph_is_re_encoded_into_a_responsive_set(): void
    {
        $property = $this->listing();

        $asset = app(StoreListingPhoto::class)($property, $this->realJpeg());

        $this->assertSame('photo', $asset->kind->value);
        $this->assertTrue($asset->is_cover, 'the first photograph should lead');

        // NFR-02: the browser must be able to choose a width.
        $this->assertArrayHasKey('400', $asset->renditions);
        $this->assertArrayHasKey('800', $asset->renditions);
        $this->assertArrayHasKey('jpg', $asset->renditions);

        foreach ($asset->renditions as $path) {
            Storage::disk('public')->assertExists($path);
        }

        $this->assertNotEmpty($asset->srcset());
        $this->assertSame(1400, $asset->width);
    }

    /** SEC-04: the filename is not evidence of anything. */
    public function test_a_disguised_file_is_rejected(): void
    {
        $property = $this->listing();

        $path = tempnam(sys_get_temp_dir(), 'evil').'.jpg';
        file_put_contents($path, "<?php echo 'pwned'; ?>");
        $disguised = new UploadedFile($path, 'cover.jpg', 'image/jpeg', null, true);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        try {
            app(StoreListingPhoto::class)($property, $disguised);
        } finally {
            $this->assertSame(0, $property->media()->count(), 'nothing should have been stored');
        }
    }

    /**
     * FR-M3-07 / NFR-04: EXIF must not survive upload.
     *
     * The source image here carries a genuine APP1/Exif segment, spliced in
     * below. Asserting only that the *output* has no EXIF would pass trivially
     * on an input that never had any — the test has to prove the segment was
     * there and was removed.
     */
    public function test_exif_does_not_survive_re_encoding(): void
    {
        $source = $this->jpegWithExif();

        $this->assertStringContainsString(
            "Exif\0\0",
            file_get_contents($source->getRealPath()),
            'the fixture should carry an EXIF segment to begin with'
        );

        $asset = app(StoreListingPhoto::class)($this->listing(), $source);

        foreach ($asset->renditions as $path) {
            $bytes = Storage::disk('public')->get($path);

            $this->assertStringNotContainsString("Exif\0\0", $bytes, "EXIF survived into {$path}");
            $this->assertStringNotContainsString('http://ns.adobe.com/xap', $bytes, "XMP survived into {$path}");
        }

        $exif = @exif_read_data(Storage::disk('public')->path($asset->renditions['jpg']));

        $this->assertTrue(
            $exif === false || ! isset($exif['GPSLatitude']),
            'stored image still carries EXIF GPS data'
        );
    }

    /**
     * A JPEG carrying a real APP1/Exif segment, spliced in after the SOI marker.
     *
     * Hand-built rather than pulled from a fixture file so the test is
     * self-contained and the bytes being asserted on are visible here.
     */
    private function jpegWithExif(): UploadedFile
    {
        $plain = $this->realJpeg(800, 600, seed: 3);
        $bytes = file_get_contents($plain->getRealPath());

        // TIFF header (little endian) + one IFD entry (Orientation = 1).
        $tiff = "II\x2A\x00\x08\x00\x00\x00"
              ."\x01\x00"
              ."\x12\x01\x03\x00\x01\x00\x00\x00\x01\x00\x00\x00"
              ."\x00\x00\x00\x00";

        $payload = "Exif\x00\x00".$tiff;
        $app1    = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        // Insert immediately after SOI (FFD8), which is where APP1 belongs.
        $withExif = substr($bytes, 0, 2).$app1.substr($bytes, 2);

        $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
        file_put_contents($path, $withExif);

        return new UploadedFile($path, 'holiday.jpg', 'image/jpeg', null, true);
    }

    /** FR-M3-08: the same photograph reappearing must be detectable. */
    public function test_the_same_photograph_produces_a_matching_hash(): void
    {
        $a = app(StoreListingPhoto::class)($this->listing(), $this->realJpeg(1400, 1050, seed: 7));
        // Same image content at a different size — as a re-upload usually is.
        $b = app(StoreListingPhoto::class)($this->listing(), $this->realJpeg(700, 525, seed: 7));

        $this->assertSame(16, strlen($a->phash), 'a 64-bit hash is 16 hex characters');

        // Guard against a hash that is uniform: an all-zero or all-F result
        // collides with everything, and would make the comparison below pass
        // without measuring anything.
        $this->assertNotSame('0000000000000000', $a->phash, 'degenerate hash');
        $this->assertNotSame('ffffffffffffffff', $a->phash, 'degenerate hash');

        $this->assertLessThanOrEqual(
            10,
            ImageProcessor::hashDistance($a->phash, $b->phash),
            'the same photograph at two sizes should hash close together'
        );
    }

    /** A hash that matches everything is worse than no hash. */
    public function test_different_photographs_hash_far_apart(): void
    {
        $a = app(StoreListingPhoto::class)($this->listing(), $this->realJpeg(900, 675, seed: 11));
        $b = app(StoreListingPhoto::class)($this->listing(), $this->realJpeg(900, 675, seed: 99));

        $this->assertGreaterThan(
            10,
            ImageProcessor::hashDistance($a->phash, $b->phash),
            'two unrelated photographs must not look like duplicates'
        );
    }

    public function test_the_photograph_cap_is_enforced(): void
    {
        config(['agentpro.media.max_photos' => 2]);
        $property = $this->listing();

        app(StoreListingPhoto::class)($property, $this->realJpeg(400, 300));
        app(StoreListingPhoto::class)($property, $this->realJpeg(400, 300));

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(StoreListingPhoto::class)($property, $this->realJpeg(400, 300));
    }

    /** SEC-03 */
    public function test_a_lister_cannot_upload_to_another_listers_property(): void
    {
        $property = $this->listing();

        $this->actingAs($this->lister())
            ->post(route('lister.media.photos', $property), [
                'photos' => [$this->realJpeg(400, 300)],
            ])
            ->assertForbidden();

        $this->assertSame(0, $property->media()->count());
    }

    public function test_the_owner_can_upload_and_set_a_cover(): void
    {
        $property = $this->listing();

        $this->actingAs($property->lister)
            ->post(route('lister.media.photos', $property), [
                'photos' => [$this->realJpeg(600, 450), $this->realJpeg(600, 450)],
            ])
            ->assertRedirect();

        $this->assertSame(2, $property->media()->where('kind', 'photo')->count());

        $second = $this->photos($property)->last();
        $this->assertFalse($second->is_cover, 'the second upload should not lead');

        $this->actingAs($property->lister)
            ->post(route('lister.media.cover', [$property, $second]))
            ->assertRedirect();

        $this->assertTrue($second->fresh()->is_cover);
        $this->assertSame(1, $property->media()->where('is_cover', true)->count(), 'only one cover');
    }

    /**
     * Regression: choosing the photograph that is already the cover must leave
     * it as the cover. The obvious implementation clears every cover flag and
     * then relies on a model update that Eloquent skips as not-dirty, which
     * silently leaves the listing with no cover at all.
     */
    public function test_reselecting_the_current_cover_keeps_it(): void
    {
        $property = $this->listing();

        app(StoreListingPhoto::class)($property, $this->realJpeg(600, 450));
        app(StoreListingPhoto::class)($property, $this->realJpeg(600, 450));

        $cover = $this->photos($property)->firstWhere('is_cover', true);
        $this->assertNotNull($cover);

        $this->actingAs($property->lister)
            ->post(route('lister.media.cover', [$property, $cover]))
            ->assertRedirect();

        $this->assertTrue($cover->fresh()->is_cover, 'the listing was left with no cover');
        $this->assertSame(1, $property->media()->where('is_cover', true)->count());
    }

    public function test_deleting_a_photograph_removes_its_renditions_and_promotes_a_new_cover(): void
    {
        $property = $this->listing();

        $first  = app(StoreListingPhoto::class)($property, $this->realJpeg(600, 450));
        $second = app(StoreListingPhoto::class)($property, $this->realJpeg(600, 450));

        $paths = array_values($first->renditions);

        $this->actingAs($property->lister)
            ->delete(route('lister.media.destroy', [$property, $first]))
            ->assertRedirect();

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }

        $this->assertTrue($second->fresh()->is_cover, 'a listing must not be left without a cover');
    }

    /** FR-M3-12: video is not visible until a moderator clears it. */
    public function test_pending_video_is_not_shown_on_the_public_listing(): void
    {
        $property = $this->listing();

        $property->media()->create([
            'uuid' => Str::uuid(), 'kind' => 'video', 'disk' => 'public',
            'path' => 'media/x/clip.mp4', 'source' => 'lister',
            'moderation_state' => 'pending', 'sort_order' => 0,
        ]);

        $property->update([
            'lifecycle_state' => LifecycleState::Published->value,
            'published_at' => now(),
        ]);

        $this->get(route('property.show', $property))
            ->assertOk()
            ->assertDontSee('Walkthrough video');

        $property->media()->where('kind', 'video')->update(['moderation_state' => 'approved']);

        $this->get(route('property.show', $property->fresh()))
            ->assertOk()
            ->assertSee('Walkthrough video');
    }
}
