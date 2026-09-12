<?php

namespace App\Http\Controllers\Lister;

use App\Actions\StoreListingPhoto;
use App\Actions\StoreListingVideo;
use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function storePhotos(Request $request, Property $property, StoreListingPhoto $store)
    {
        $this->authorize('update', $property);

        $request->validate([
            'photos'   => ['required', 'array', 'max:20'],
            'photos.*' => ['file', 'max:'.config('agentpro.media.max_photo_kb')],
        ], [
            'photos.*.max' => 'Each photograph must be under '
                .round(config('agentpro.media.max_photo_kb') / 1024).' MB.',
        ]);

        $stored = 0;

        foreach ($request->file('photos') as $file) {
            $store($property, $file);
            $stored++;
        }

        return back()->with('status', $stored.' '.str('photograph')->plural($stored).' added.');
    }

    public function storeVideo(Request $request, Property $property, StoreListingVideo $store)
    {
        $this->authorize('update', $property);

        $request->validate([
            'video' => ['required', 'file', 'max:'.config('agentpro.media.max_video_kb')],
        ], [
            'video.max' => 'The video must be under '
                .round(config('agentpro.media.max_video_kb') / 1024).' MB.',
        ]);

        $store($property, $request->file('video'));

        return back()->with(
            'status',
            'Video uploaded. It is queued for processing and will be reviewed before it appears on the listing.'
        );
    }

    /** FR-M3-01: the lister chooses which photograph leads. */
    public function setCover(Property $property, MediaAsset $media)
    {
        $this->authorize('update', $property);
        abort_unless($media->property_id === $property->id, 404);

        // Both writes are direct queries inside a transaction, deliberately.
        //
        // $media->update(['is_cover' => true]) looks equivalent and is not: when
        // the chosen photograph is already the cover, that attribute is not
        // dirty, Eloquent skips the write, and the blanket clear above has
        // already set the row to false — leaving the listing with no cover at
        // all. The transaction means there is never an instant where none is set.
        DB::transaction(function () use ($property, $media) {
            $property->media()->where('kind', 'photo')->update(['is_cover' => false]);
            MediaAsset::whereKey($media->getKey())->update(['is_cover' => true]);
        });

        return back()->with('status', 'Cover photograph updated.');
    }

    /** FR-M3-01: ordering, submitted as the full sequence rather than swaps. */
    public function reorder(Request $request, Property $property)
    {
        $this->authorize('update', $property);

        $data = $request->validate([
            'order'   => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        // Scoped to this property's own assets, so a crafted payload cannot
        // reorder someone else's media (SEC-03).
        $owned = $property->media()->pluck('id')->all();

        foreach (array_values($data['order']) as $position => $id) {
            if (in_array((int) $id, $owned, true)) {
                MediaAsset::where('id', $id)->update(['sort_order' => $position]);
            }
        }

        return back()->with('status', 'Order saved.');
    }

    public function destroy(Property $property, MediaAsset $media)
    {
        $this->authorize('update', $property);
        abort_unless($media->property_id === $property->id, 404);

        // Remove the derivatives as well as the original, or the disk fills up
        // with renditions nothing references.
        $paths = array_values($media->renditions ?? []);
        $paths[] = $media->path;
        $paths[] = $media->poster_path;

        foreach (array_filter($paths) as $path) {
            Storage::disk($media->disk ?? config('agentpro.media.disk'))->delete($path);
        }

        $wasCover = $media->is_cover;
        $media->delete();

        // Something must lead. Promote the next photograph rather than leaving
        // the listing without a cover.
        if ($wasCover) {
            $property->media()->where('kind', 'photo')->orderBy('sort_order')->first()
                ?->update(['is_cover' => true]);
        }

        Audit::record('media.deleted', $property, ['media_uuid' => $media->uuid], []);

        return back()->with('status', 'Removed.');
    }
}
