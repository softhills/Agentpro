<?php

namespace App\Http\Controllers\Admin;

use App\Actions\RetagTaxonomy;
use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\Area;
use App\Support\Audit;
use App\Support\Vocab;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Amenities and areas (FR-M12-06).
 *
 * The last thing in the console that still needed a seeder edit and a
 * deployment. Adding "Borehole" to the amenity list is a product decision
 * somebody makes on a Tuesday, not a release.
 *
 * What is deliberately NOT editable here: the controlled vocabularies in
 * App\Support\Vocab — title types, RealSure components, reject and unpublish
 * reasons. Those are shown on this page read-only, with the reason. They carry
 * legal weight and feed reporting, so changing one should go through review and
 * a deployment rather than a text box. Being able to *see* them without reading
 * the source is still worth the screen space, because an operator writing a
 * rejection needs to know what the codes are.
 */
class TaxonomyController extends Controller
{
    public function index()
    {
        return view('admin.taxonomy', [
            'amenities' => Amenity::withCount('properties')
                ->orderBy('group')->orderBy('sort_order')->orderBy('name')->get()
                ->groupBy('group'),
            'areas' => Area::withCount([
                'properties',
                'properties as live_count' => fn ($q) => $q->where('lifecycle_state', 'published'),
                // Loaded so Delete is only offered where it would be allowed —
                // an action that is always refused is worse than no action.
                'technicianSlots',
            ])->orderBy('state')->orderBy('city')->orderBy('name')->get(),
            // Used by the merge pickers, so an operator is choosing from real
            // terms rather than typing one.
            'allAmenities' => Amenity::orderBy('name')->get(['id', 'name', 'slug']),
            'allAreas'     => Area::orderBy('name')->get(['id', 'name', 'slug', 'city']),
            'vocabularies' => [
                'Title types (FR-M6-04)'       => Vocab::flatTitleTypes(),
                'RealSure components'          => Vocab::REALSURE_COMPONENTS,
                'Listing tags (FR-M2-04)'      => Vocab::TAGS,
                'Rejection reasons (FR-M12-01)' => Vocab::REJECT_REASONS,
                'Unpublish reasons (FR-M2-07)' => Vocab::UNPUBLISH_REASONS,
                'Report reasons (FR-M6-07)'    => Vocab::REPORT_REASONS,
            ],
            'groups' => Amenity::query()->distinct()->orderBy('group')->pluck('group'),
        ]);
    }

    // ------------------------------------------------------------- amenities

    public function storeAmenity(Request $request)
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:60'],
            'group'         => ['required', 'string', 'max:40'],
            'slug'          => ['nullable', 'string', 'max:60', 'alpha_dash', 'unique:amenities,slug'],
            'is_filterable' => ['nullable', 'boolean'],
        ]);

        $amenity = Amenity::create([
            'name'          => $data['name'],
            'group'         => Str::lower($data['group']),
            'slug'          => ($data['slug'] ?? null) ?: $this->uniqueSlug('amenities', $data['name']),
            'is_filterable' => (bool) ($data['is_filterable'] ?? true),
            'sort_order'    => (int) Amenity::where('group', Str::lower($data['group']))->max('sort_order') + 10,
        ]);

        Audit::record('amenity.created', $amenity, [], $amenity->only(['name', 'slug', 'group']));

        return back()->with('status', '"'.$amenity->name.'" added. Listers will see it on the next listing they edit.');
    }

    /**
     * Rename, regroup, reorder, or change what it is filtered by.
     *
     * The slug goes through RetagTaxonomy rather than being saved with the rest:
     * it is the value saved searches and shared links hold, so changing it has
     * to carry them along. Everything else here is cosmetic.
     */
    public function updateAmenity(Request $request, Amenity $amenity, RetagTaxonomy $retag)
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:60'],
            'group'         => ['required', 'string', 'max:40'],
            'slug'          => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique('amenities', 'slug')->ignore($amenity->id)],
            'sort_order'    => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_filterable' => ['nullable', 'boolean'],
        ]);

        $before = $amenity->only(['name', 'slug', 'group', 'sort_order', 'is_filterable']);
        $rewritten = 0;

        try {
            $rewritten = $retag->renameAmenitySlug($amenity, $data['slug']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['slug' => $e->getMessage()])->withInput();
        }

        $amenity->update([
            'name'          => $data['name'],
            'group'         => Str::lower($data['group']),
            'sort_order'    => (int) ($data['sort_order'] ?? $amenity->sort_order),
            'is_filterable' => (bool) ($data['is_filterable'] ?? false),
        ]);

        Audit::record('amenity.updated', $amenity, $before, $amenity->only(['name', 'slug', 'group', 'sort_order', 'is_filterable']));

        return back()->with('status', $rewritten > 0
            ? 'Saved. The slug changed, so '.$rewritten.' saved '.Str::plural('search', $rewritten).' were updated to match.'
            : 'Saved.');
    }

    /**
     * Delete, but only when nothing points at it.
     *
     * The pivot cascades, so deleting an amenity in use would quietly strip it
     * from every listing carrying it — no error, no record, and no way to tell
     * afterwards which listings had it. Merging is what the operator almost
     * always means, so the refusal says so.
     */
    public function destroyAmenity(Amenity $amenity)
    {
        $inUse = $amenity->properties()->count();

        if ($inUse > 0) {
            return back()->withErrors(['amenity' => sprintf(
                '"%s" is on %d %s. Deleting it would strip it from all of them. Merge it into another amenity instead.',
                $amenity->name,
                $inUse,
                Str::plural('listing', $inUse),
            )]);
        }

        Audit::record('amenity.deleted', $amenity, $amenity->only(['name', 'slug', 'group']), []);

        $amenity->delete();

        return back()->with('status', 'Deleted.');
    }

    public function mergeAmenity(Request $request, Amenity $amenity, RetagTaxonomy $retag)
    {
        $data = $request->validate([
            'into' => ['required', 'exists:amenities,id'],
        ]);

        $into = Amenity::findOrFail($data['into']);

        try {
            $result = $retag->mergeAmenities($amenity, $into);
        } catch (RuntimeException $e) {
            return back()->withErrors(['into' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            'Merged into "%s": %d %s moved, %d saved %s updated.',
            $into->name,
            $result['listings'],
            Str::plural('listing', $result['listings']),
            $result['searches'],
            Str::plural('search', $result['searches']),
        ));
    }

    // ----------------------------------------------------------------- areas

    public function storeArea(Request $request)
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:80'],
            'city'         => ['required', 'string', 'max:80'],
            'state'        => ['required', 'string', 'max:80'],
            'slug'         => ['nullable', 'string', 'max:80', 'alpha_dash', 'unique:areas,slug'],
            'centroid_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'centroid_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'default_zoom' => ['nullable', 'integer', 'between:8,18'],
        ]);

        $area = Area::create([
            'name'         => $data['name'],
            'city'         => $data['city'],
            'state'        => $data['state'],
            'slug'         => ($data['slug'] ?? null) ?: $this->uniqueSlug('areas', $data['name'].'-'.$data['city']),
            'centroid_lat' => $data['centroid_lat'] ?? null,
            'centroid_lng' => $data['centroid_lng'] ?? null,
            'default_zoom' => $data['default_zoom'] ?? 14,
            // Never on by creation. Coverage commits the field team to serving
            // the area, which is an Operations decision made on the coverage
            // screen, not a side effect of adding a name to a list (FR-M4-02).
            'is_scan_coverage' => false,
        ]);

        Audit::record('area.created', $area, [], $area->only(['name', 'slug', 'city', 'state']));

        return back()->with('status', $area->name.' added. Open it for 3D capture on Coverage & capacity when the field team can service it.');
    }

    public function updateArea(Request $request, Area $area, RetagTaxonomy $retag)
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:80'],
            'city'         => ['required', 'string', 'max:80'],
            'state'        => ['required', 'string', 'max:80'],
            'slug'         => ['required', 'string', 'max:80', 'alpha_dash', Rule::unique('areas', 'slug')->ignore($area->id)],
            'centroid_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'centroid_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'default_zoom' => ['nullable', 'integer', 'between:8,18'],
        ]);

        $before = $area->only(['name', 'slug', 'city', 'state', 'centroid_lat', 'centroid_lng', 'default_zoom']);
        $rewritten = 0;

        try {
            $rewritten = $retag->renameAreaSlug($area, $data['slug']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['slug' => $e->getMessage()])->withInput();
        }

        $area->update([
            'name'         => $data['name'],
            'city'         => $data['city'],
            'state'        => $data['state'],
            'centroid_lat' => $data['centroid_lat'] ?? null,
            'centroid_lng' => $data['centroid_lng'] ?? null,
            'default_zoom' => $data['default_zoom'] ?? $area->default_zoom,
        ]);

        Audit::record('area.updated', $area, $before, $area->only(['name', 'slug', 'city', 'state', 'centroid_lat', 'centroid_lng', 'default_zoom']));

        return back()->with('status', $rewritten > 0
            ? 'Saved. The slug changed, so '.$rewritten.' saved '.Str::plural('search', $rewritten).' were updated to match.'
            : 'Saved.');
    }

    /**
     * An area with listings is never deleted.
     *
     * The foreign key is nullOnDelete, so deleting one does not remove the
     * listings — it detaches them. They stay published and searchable by every
     * filter except the area they are in, which is worse than either extreme
     * because nothing looks broken.
     */
    public function destroyArea(Area $area)
    {
        $inUse = $area->properties()->count();

        if ($inUse > 0) {
            return back()->withErrors(['area' => sprintf(
                '%s has %d %s. Deleting it would leave them with no area at all — merge it into a neighbouring area instead.',
                $area->name,
                $inUse,
                Str::plural('listing', $inUse),
            )]);
        }

        if (DB::table('technician_slots')->where('area_id', $area->id)->exists()) {
            return back()->withErrors(['area' => $area->name.' still has capture slots booked against it. Merge it, or let the slots pass first.']);
        }

        Audit::record('area.deleted', $area, $area->only(['name', 'slug', 'city', 'state']), []);

        $area->delete();

        return back()->with('status', 'Deleted.');
    }

    public function mergeArea(Request $request, Area $area, RetagTaxonomy $retag)
    {
        $data = $request->validate([
            'into' => ['required', 'exists:areas,id'],
        ]);

        $into = Area::findOrFail($data['into']);

        try {
            $result = $retag->mergeAreas($area, $into);
        } catch (RuntimeException $e) {
            return back()->withErrors(['into' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            'Merged into %s: %d %s, %d capture %s and %d saved %s moved across.',
            $into->name,
            $result['listings'],
            Str::plural('listing', $result['listings']),
            $result['slots'],
            Str::plural('slot', $result['slots']),
            $result['searches'],
            Str::plural('search', $result['searches']),
        ));
    }

    /** Slugs are permanent enough to be worth not colliding on creation. */
    private function uniqueSlug(string $table, string $source): string
    {
        $base = Str::slug($source);
        $slug = $base;
        $suffix = 2;

        while (DB::table($table)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
