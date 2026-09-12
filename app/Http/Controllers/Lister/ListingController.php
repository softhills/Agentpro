<?php

namespace App\Http\Controllers\Lister;

use App\Actions\SubmitListingForReview;
use App\Enums\LifecycleState;
use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\Area;
use App\Models\Property;
use App\Models\Unit;
use App\Support\Audit;
use App\Support\Vocab;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ListingController extends Controller
{
    public function create(Request $request)
    {
        $this->authorize('create', Property::class);

        return view('lister.listing-form', [
            'property'   => new Property(['listing_type' => 'apartment', 'intent' => 'rent']),
            'unit'       => new Unit(['price_period' => 'year']),
            'areas'      => Area::orderBy('city')->orderBy('name')->get(),
            'amenities'  => Amenity::orderBy('sort_order')->get(),
            'titleTypes' => Vocab::TITLE_TYPES,
            'selectedAmenities' => [],
            'selectedTitles'    => [],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Property::class);

        $data = $this->validated($request);

        $property = DB::transaction(function () use ($data, $request) {
            $area = Area::find($data['area_id']);

            $property = Property::create([
                'uuid'          => Str::uuid(),
                'lister_id'     => $request->user()->id,
                'organisation_id' => $request->user()->organisation_id,
                'area_id'       => $area?->id,
                'title'         => $data['title'],
                'slug'          => Str::slug($data['title']).'-'.Str::lower(Str::random(5)),
                'description'   => $data['description'] ?? null,
                'listing_type'  => $data['listing_type'],
                'intent'        => $data['intent'],
                'build_status'  => $data['build_status'],
                'finish'        => $data['finish'] ?? null,
                'address_line'  => $data['address_line'],
                'city'          => $area?->city ?? $data['city'],
                'state'         => $area?->state ?? $data['state'],
                'lat'           => $data['lat'],
                'lng'           => $data['lng'],
                'location'      => DB::raw(sprintf("ST_GeomFromText('POINT(%F %F)')", $data['lng'], $data['lat'])),
                'what3words'    => $data['what3words'] ?? null,
                'website_url'   => $data['website_url'] ?? null,
                // Everything starts as a draft. Nothing reaches the public
                // without passing through review (FR-M2-06).
                'lifecycle_state' => LifecycleState::Draft->value,
                'content_updated_at' => now(),
            ]);

            $this->syncChildren($property, $data);

            Audit::record('listing.created', $property, [], ['title' => $property->title]);

            return $property;
        });

        return redirect()
            ->route('lister.listings.edit', $property)
            ->with('status', 'Draft saved. Add photographs, then submit for review.');
    }

    public function edit(Property $property)
    {
        $this->authorize('update', $property);

        $property->load(['units.feeLines', 'titleClaims', 'amenities', 'media']);

        return view('lister.listing-form', [
            'property'   => $property,
            'unit'       => $property->headlineUnit() ?? new Unit(['price_period' => 'year']),
            'areas'      => Area::orderBy('city')->orderBy('name')->get(),
            'amenities'  => Amenity::orderBy('sort_order')->get(),
            'titleTypes' => Vocab::TITLE_TYPES,
            'selectedAmenities' => $property->amenities->pluck('id')->all(),
            'selectedTitles'    => $property->titleClaims->pluck('stage', 'title_type')->all(),
        ]);
    }

    public function update(Request $request, Property $property)
    {
        $this->authorize('update', $property);

        $data = $this->validated($request);

        DB::transaction(function () use ($property, $data) {
            $area   = Area::find($data['area_id']);
            $before = $property->only(['title', 'lifecycle_state']);

            $property->update([
                'area_id'      => $area?->id,
                'title'        => $data['title'],
                'description'  => $data['description'] ?? null,
                'listing_type' => $data['listing_type'],
                'intent'       => $data['intent'],
                'build_status' => $data['build_status'],
                'finish'       => $data['finish'] ?? null,
                'address_line' => $data['address_line'],
                'city'         => $area?->city ?? $data['city'],
                'state'        => $area?->state ?? $data['state'],
                'lat'          => $data['lat'],
                'lng'          => $data['lng'],
                'location'     => DB::raw(sprintf("ST_GeomFromText('POINT(%F %F)')", $data['lng'], $data['lat'])),
                'what3words'   => $data['what3words'] ?? null,
                'website_url'  => $data['website_url'] ?? null,
                'content_updated_at' => now(),
            ]);

            $this->syncChildren($property, $data);

            Audit::record('listing.updated', $property, $before, ['title' => $property->title]);
        });

        return back()->with('status', 'Changes saved.');
    }

    public function submit(Property $property, SubmitListingForReview $submitter)
    {
        $this->authorize('submit', $property);

        $property->load(['units.feeLines', 'titleClaims', 'media', 'lister']);

        $submitter($property);   // throws ValidationException listing every blocker

        return redirect()
            ->route('lister.dashboard')
            ->with('status', 'Submitted for review. Decisions usually come within 6 working hours.');
    }

    // ------------------------------------------------------------------ helpers

    private function validated(Request $request): array
    {
        return $request->validate([
            'title'        => ['required', 'string', 'max:160'],
            'description'  => ['nullable', 'string', 'max:4000'],
            'listing_type' => ['required', 'in:land,house,apartment'],
            'intent'       => ['required', 'in:rent,sale'],
            'build_status' => ['required', 'in:fully_built,under_construction'],
            'finish'       => ['nullable', 'in:furnished,unfurnished,core,carcass'],
            'area_id'      => ['nullable', 'exists:areas,id'],
            'address_line' => ['required', 'string', 'max:200'],
            'city'         => ['required_without:area_id', 'nullable', 'string', 'max:80'],
            'state'        => ['required_without:area_id', 'nullable', 'string', 'max:80'],
            'lat'          => ['required', 'numeric', 'between:-90,90'],
            'lng'          => ['required', 'numeric', 'between:-180,180'],
            'what3words'   => ['nullable', 'string', 'max:120'],
            // SEC-09: stored and rendered rel="nofollow ugc", never fetched server-side.
            'website_url'  => ['nullable', 'url:http,https', 'max:255'],

            'unit'                  => ['required', 'array'],
            'unit.price'            => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'unit.price_period'     => ['required', 'in:year,month,night,once'],
            'unit.bedrooms'         => ['nullable', 'integer', 'min:0', 'max:50'],
            'unit.bathrooms'        => ['nullable', 'integer', 'min:0', 'max:50'],
            'unit.toilets'          => ['nullable', 'integer', 'min:0', 'max:50'],
            'unit.floor_area_sqm'   => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'unit.available_from'   => ['nullable', 'date'],

            'fees'              => ['nullable', 'array', 'max:12'],
            'fees.*.label'      => ['required_with:fees.*.amount', 'nullable', 'string', 'max:80'],
            'fees.*.amount'     => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'fees.*.is_refundable' => ['nullable', 'boolean'],
            'fees.*.payee'      => ['nullable', 'string', 'max:80'],

            'titles'        => ['nullable', 'array', 'max:19'],
            'titles.*'      => ['in:available,in_progress,none'],

            'amenities'     => ['nullable', 'array', 'max:40'],
            'amenities.*'   => ['exists:amenities,id'],
        ]);
    }

    /** Units, fees, titles and amenities, shared by store and update. */
    private function syncChildren(Property $property, array $data): void
    {
        $unitData = $data['unit'];

        $unit = $property->units()->where('is_primary', true)->first()
            ?? $property->units()->create([
                'uuid'         => Str::uuid(),
                'is_primary'   => true,
                'price'        => $unitData['price'],
                'price_period' => $unitData['price_period'],
            ]);

        $priceChanged = (float) $unit->price !== (float) $unitData['price'];

        $unit->update([
            'price'          => $unitData['price'],
            'price_period'   => $unitData['price_period'],
            'bedrooms'       => $unitData['bedrooms'] ?? null,
            'bathrooms'      => $unitData['bathrooms'] ?? null,
            'toilets'        => $unitData['toilets'] ?? null,
            'floor_area_sqm' => $unitData['floor_area_sqm'] ?? null,
            'available_from' => $unitData['available_from'] ?? null,
        ]);

        // FR-M2-04: price history is written on every change, because the
        // price-drop tag is derived from it rather than chosen by the lister.
        if ($priceChanged || $unit->priceHistory()->count() === 0) {
            $unit->priceHistory()->create([
                'price'        => $unitData['price'],
                'price_period' => $unitData['price_period'],
                'effective_at' => now(),
            ]);
        }

        // Fee lines are replaced wholesale — simpler than diffing, and the
        // breakdown is small enough that it costs nothing.
        $unit->feeLines()->delete();

        foreach (array_values($data['fees'] ?? []) as $i => $fee) {
            if (blank($fee['label'] ?? null) || ! isset($fee['amount'])) {
                continue;
            }

            $unit->feeLines()->create([
                'label'         => $fee['label'],
                'amount'        => $fee['amount'],
                'calc_type'     => 'fixed',
                'is_refundable' => (bool) ($fee['is_refundable'] ?? false),
                'payee'         => $fee['payee'] ?? null,
                'sort_order'    => $i,
            ]);
        }

        $property->titleClaims()->delete();

        foreach ($data['titles'] ?? [] as $type => $stage) {
            if ($stage === 'none' || ! array_key_exists($type, Vocab::flatTitleTypes())) {
                continue;
            }

            $property->titleClaims()->create([
                'title_type'  => $type,
                'stage'       => $stage,
                'declared_at' => now(),
            ]);
        }

        $property->amenities()->sync($data['amenities'] ?? []);
    }
}
