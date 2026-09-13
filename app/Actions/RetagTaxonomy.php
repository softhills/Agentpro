<?php

namespace App\Actions;

use App\Models\Amenity;
use App\Models\Area;
use App\Models\SavedSearch;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Changing a taxonomy term without breaking what points at it.
 *
 * This is the whole reason taxonomy administration is not a CRUD screen.
 *
 * Amenities and areas are filtered by *slug*, not by id — `PropertySearch`
 * does `whereHas('amenities', fn ($q) => $q->where('slug', $slug))` — and a
 * saved search stores the criteria it was built from, slugs included. So a
 * slug is not an internal detail that can be tidied up: it is a foreign key
 * held in a JSON column by every seeker who saved a search, and in the query
 * string of every link anyone has ever shared.
 *
 * Rename the slug in place and nothing errors. The amenity still exists, the
 * saved search still runs, and it silently returns a different set of results
 * from the one the seeker asked for — or none at all. Nobody reports that,
 * because from the outside it looks like the market went quiet.
 *
 * So every operation here rewrites the references as part of the same
 * transaction as the change itself.
 */
class RetagTaxonomy
{
    /**
     * Rename an amenity's slug and carry every saved search with it.
     *
     * The display name is free to change at any time and is not this method's
     * business — only the slug is load-bearing.
     */
    public function renameAmenitySlug(Amenity $amenity, string $newSlug): int
    {
        return $this->rename($amenity, 'amenities', $newSlug, function (string $old, string $new) {
            return $this->rewriteSavedSearches(
                fn (array $criteria) => $this->replaceInList($criteria, 'amenities', $old, $new)
            );
        });
    }

    public function renameAreaSlug(Area $area, string $newSlug): int
    {
        return $this->rename($area, 'areas', $newSlug, function (string $old, string $new) {
            return $this->rewriteSavedSearches(
                fn (array $criteria) => $this->replaceScalar($criteria, 'area', $old, $new)
            );
        });
    }

    /**
     * Fold one amenity into another.
     *
     * The operation actually wanted when "Borehole" and "Bore hole" both turn
     * up in the list — which they will, because the seed is a starting point
     * and listers see whatever is on the form. Deleting the duplicate would
     * strip it from every listing that used it; merging keeps the listings and
     * loses only the duplicate term.
     *
     * @return array{listings: int, searches: int}
     */
    public function mergeAmenities(Amenity $from, Amenity $into): array
    {
        if ($from->id === $into->id) {
            throw new RuntimeException('An amenity cannot be merged into itself.');
        }

        return DB::transaction(function () use ($from, $into) {
            /*
             * Properties that already carry both would violate the composite
             * primary key on the pivot, so they are dropped rather than moved.
             * An INSERT … SELECT with a NOT EXISTS guard does this in one
             * statement; updating the pivot in place would fail on the first
             * listing that had tagged both.
             */
            $moved = DB::table('amenity_property')
                ->where('amenity_id', $from->id)
                ->whereNotExists(function ($query) use ($into) {
                    $query->select(DB::raw(1))
                        ->from('amenity_property as existing')
                        ->whereColumn('existing.property_id', 'amenity_property.property_id')
                        ->where('existing.amenity_id', $into->id);
                })
                ->update(['amenity_id' => $into->id]);

            // Whatever is left is a listing that had both; the row is redundant.
            DB::table('amenity_property')->where('amenity_id', $from->id)->delete();

            $searches = $this->rewriteSavedSearches(
                fn (array $criteria) => $this->replaceInList($criteria, 'amenities', $from->slug, $into->slug)
            );

            Audit::record('amenity.merged', $into, [
                'from' => ['id' => $from->id, 'name' => $from->name, 'slug' => $from->slug],
            ], [
                'into'     => $into->slug,
                'listings' => $moved,
                'searches' => $searches,
            ]);

            $from->delete();

            return ['listings' => $moved, 'searches' => $searches];
        });
    }

    /**
     * Fold one area into another.
     *
     * Unlike amenities this moves a plain foreign key, so no duplicate can
     * arise — but the same slug problem does, plus technician slots, which
     * belong to the surviving area or the capacity silently disappears.
     *
     * @return array{listings: int, searches: int, slots: int}
     */
    public function mergeAreas(Area $from, Area $into): array
    {
        if ($from->id === $into->id) {
            throw new RuntimeException('An area cannot be merged into itself.');
        }

        return DB::transaction(function () use ($from, $into) {
            $listings = DB::table('properties')->where('area_id', $from->id)
                ->update(['area_id' => $into->id]);

            $slots = DB::table('technician_slots')->where('area_id', $from->id)
                ->update(['area_id' => $into->id]);

            DB::table('scan_jobs')->where('area_id', $from->id)->update(['area_id' => $into->id]);

            $searches = $this->rewriteSavedSearches(
                fn (array $criteria) => $this->replaceScalar($criteria, 'area', $from->slug, $into->slug)
            );

            Audit::record('area.merged', $into, [
                'from' => ['id' => $from->id, 'name' => $from->name, 'slug' => $from->slug],
            ], [
                'into'     => $into->slug,
                'listings' => $listings,
                'slots'    => $slots,
                'searches' => $searches,
            ]);

            $from->delete();

            return ['listings' => $listings, 'searches' => $searches, 'slots' => $slots];
        });
    }

    /** @param  \Closure(string, string): int  $rewrite */
    private function rename(Model $term, string $table, string $newSlug, \Closure $rewrite): int
    {
        $old = $term->slug;

        if ($old === $newSlug) {
            return 0;
        }

        if (DB::table($table)->where('slug', $newSlug)->exists()) {
            throw new RuntimeException('Another entry already uses the slug "'.$newSlug.'".');
        }

        return DB::transaction(function () use ($term, $old, $newSlug, $rewrite) {
            $term->update(['slug' => $newSlug]);

            $searches = $rewrite($old, $newSlug);

            Audit::record(
                class_basename($term) === 'Area' ? 'area.slug_changed' : 'amenity.slug_changed',
                $term,
                ['slug' => $old],
                ['slug' => $newSlug, 'saved_searches_rewritten' => $searches],
            );

            return $searches;
        });
    }

    /**
     * Walk saved searches and let the caller rewrite each criteria array.
     *
     * Done in PHP rather than with a JSON_REPLACE statement, because the
     * amenity criterion is an array and the surgery needed inside it differs by
     * engine — and this runs when an operator presses a button, not in a loop.
     * Chunked so a large table does not have to fit in memory.
     *
     * @param  \Closure(array): ?array  $mutate  returns the new criteria, or null to leave alone
     */
    private function rewriteSavedSearches(\Closure $mutate): int
    {
        $changed = 0;

        SavedSearch::query()->chunkById(200, function ($searches) use ($mutate, &$changed) {
            foreach ($searches as $search) {
                $updated = $mutate($search->criteria ?? []);

                if ($updated === null) {
                    continue;
                }

                $search->update(['criteria' => $updated]);
                $changed++;
            }
        });

        return $changed;
    }

    /** @return array|null */
    private function replaceInList(array $criteria, string $key, string $old, string $new): ?array
    {
        $values = $criteria[$key] ?? null;

        if (! is_array($values) || ! in_array($old, $values, true)) {
            return null;
        }

        // Unique, because a merge can leave the surviving slug listed twice —
        // harmless for the query, but it would show up doubled in the seeker's
        // own description of their search.
        $criteria[$key] = array_values(array_unique(array_map(
            fn ($value) => $value === $old ? $new : $value,
            $values,
        )));

        return $criteria;
    }

    /** @return array|null */
    private function replaceScalar(array $criteria, string $key, string $old, string $new): ?array
    {
        if (($criteria[$key] ?? null) !== $old) {
            return null;
        }

        $criteria[$key] = $new;

        return $criteria;
    }
}
