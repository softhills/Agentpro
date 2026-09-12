@extends('layouts.app')

@section('title', ($property->exists ? 'Edit listing' : 'Add a listing').' — Agentpro')

@section('content')
@php
    $isEdit = $property->exists;
    $fees   = old('fees', $isEdit && $unit->exists
        ? $unit->feeLines->map(fn ($f) => [
            'label' => $f->label, 'amount' => $f->amount,
            'is_refundable' => $f->is_refundable, 'payee' => $f->payee,
          ])->values()->all()
        : [
            ['label' => 'Agency fee', 'amount' => null, 'is_refundable' => false, 'payee' => 'Agent'],
            ['label' => 'Legal fee', 'amount' => null, 'is_refundable' => false, 'payee' => 'Solicitor'],
            ['label' => 'Caution deposit', 'amount' => null, 'is_refundable' => true, 'payee' => 'Landlord'],
          ]);
@endphp

<div class="container formwrap">
    <a href="{{ route('lister.dashboard') }}" class="backlink">&larr; Back to your listings</a>
    <h1>{{ $isEdit ? 'Edit listing' : 'Add a listing' }}</h1>

    <x-flash />
    <x-form-errors />

    <form method="POST"
          action="{{ $isEdit ? route('lister.listings.update', $property) : route('lister.listings.store') }}"
          class="stack">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <section class="formsec">
            <h2>The property</h2>

            <x-field name="title" label="Listing title" :value="old('title', $property->title)" required
                     placeholder="3-Bed Apartment, Ikate" />

            <div class="row3">
                <div class="fieldset">
                    <label class="flabel" for="listing_type">Type</label>
                    <select id="listing_type" name="listing_type" class="finput" required>
                        @foreach (['apartment' => 'Apartment', 'house' => 'House', 'land' => 'Land'] as $v => $l)
                            <option value="{{ $v }}" @selected(old('listing_type', $property->listing_type) === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="fieldset">
                    <label class="flabel" for="intent">Rent or sale</label>
                    <select id="intent" name="intent" class="finput" required>
                        <option value="rent" @selected(old('intent', $property->intent) === 'rent')>For rent</option>
                        <option value="sale" @selected(old('intent', $property->intent) === 'sale')>For sale</option>
                    </select>
                </div>

                <div class="fieldset">
                    <label class="flabel" for="build_status">Build status</label>
                    <select id="build_status" name="build_status" class="finput" required>
                        <option value="fully_built" @selected(old('build_status', $property->build_status) === 'fully_built')>Fully built</option>
                        <option value="under_construction" @selected(old('build_status', $property->build_status) === 'under_construction')>Under construction</option>
                    </select>
                </div>
            </div>

            <div class="fieldset">
                <label class="flabel" for="finish">Finish</label>
                <select id="finish" name="finish" class="finput">
                    <option value="">Not applicable (land)</option>
                    @foreach (['furnished' => 'Furnished', 'unfurnished' => 'Unfurnished', 'core' => 'Core', 'carcass' => 'Carcass'] as $v => $l)
                        <option value="{{ $v }}" @selected(old('finish', $property->finish) === $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>

            <div class="fieldset">
                <label class="flabel" for="description">Description</label>
                <textarea id="description" name="description" rows="5" class="finput">{{ old('description', $property->description) }}</textarea>
                <p class="fhint">Be specific about power, water and security — it is what seekers filter on.</p>
            </div>
        </section>

        <section class="formsec">
            <h2>Where it is</h2>

            <div class="fieldset">
                <label class="flabel" for="area_id">Area</label>
                <select id="area_id" name="area_id" class="finput">
                    <option value="">Select an area</option>
                    @foreach ($areas->groupBy('city') as $city => $group)
                        <optgroup label="{{ $city }}">
                            @foreach ($group as $area)
                                <option value="{{ $area->id }}" @selected((int) old('area_id', $property->area_id) === $area->id)>
                                    {{ $area->name }}@if ($area->is_scan_coverage) · 3D available @endif
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <p class="fhint">Areas marked &ldquo;3D available&rdquo; are inside our capture coverage.</p>
            </div>

            <x-field name="address_line" label="Street address" :value="old('address_line', $property->address_line)" required />

            <div class="row3">
                <x-field name="lat" label="Latitude" :value="old('lat', $property->lat)" required placeholder="6.4441" />
                <x-field name="lng" label="Longitude" :value="old('lng', $property->lng)" required placeholder="3.4795" />
                <x-field name="what3words" label="what3words" :value="old('what3words', $property->what3words)" placeholder="///plant.chief.maker" />
            </div>

            <x-field name="website_url" label="Website (optional)" type="url" :value="old('website_url', $property->website_url)"
                     hint="Shown as a link on the listing. We never fetch or follow it." />
        </section>

        <section class="formsec">
            <h2>Price and size</h2>

            <div class="row3">
                <div class="fieldset">
                    <label class="flabel" for="unit-price">Price (&#8358;)</label>
                    <input id="unit-price" name="unit[price]" type="number" step="1" min="0" class="finput"
                           value="{{ old('unit.price', $unit->price) }}" required>
                </div>

                <div class="fieldset">
                    <label class="flabel" for="unit-period">Per</label>
                    <select id="unit-period" name="unit[price_period]" class="finput" required>
                        @foreach (['year' => 'Per annum', 'month' => 'Per month', 'night' => 'Per night', 'once' => 'Total (sale)'] as $v => $l)
                            <option value="{{ $v }}" @selected(old('unit.price_period', $unit->price_period?->value) === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="fieldset">
                    <label class="flabel" for="unit-available">Available from</label>
                    <input id="unit-available" name="unit[available_from]" type="date" class="finput"
                           value="{{ old('unit.available_from', $unit->available_from?->format('Y-m-d')) }}">
                </div>
            </div>

            <div class="row4">
                <div class="fieldset">
                    <label class="flabel" for="unit-beds">Bedrooms</label>
                    <input id="unit-beds" name="unit[bedrooms]" type="number" min="0" max="50" class="finput" value="{{ old('unit.bedrooms', $unit->bedrooms) }}">
                </div>
                <div class="fieldset">
                    <label class="flabel" for="unit-baths">Bathrooms</label>
                    <input id="unit-baths" name="unit[bathrooms]" type="number" min="0" max="50" class="finput" value="{{ old('unit.bathrooms', $unit->bathrooms) }}">
                </div>
                <div class="fieldset">
                    <label class="flabel" for="unit-toilets">Toilets</label>
                    <input id="unit-toilets" name="unit[toilets]" type="number" min="0" max="50" class="finput" value="{{ old('unit.toilets', $unit->toilets) }}">
                </div>
                <div class="fieldset">
                    <label class="flabel" for="unit-sqm">Floor area (m&sup2;)</label>
                    <input id="unit-sqm" name="unit[floor_area_sqm]" type="number" min="0" class="finput" value="{{ old('unit.floor_area_sqm', $unit->floor_area_sqm) }}">
                </div>
            </div>
        </section>

        {{-- FR-M7-01. This section is why a listing cannot be submitted on a
             price alone: the platform's whole promise is that the advertised
             figure is not a surprise at signing. --}}
        <section class="formsec formsec-required">
            <h2>Cost to move in <span class="reqflag">Required to publish</span></h2>
            <p class="secblurb">
                Every fee a tenant or buyer must pay on top of the price. A listing
                cannot be submitted for review without this — it is the single thing
                seekers trust Agentpro for.
            </p>

            <div class="feegrid" id="feegrid">
                <span class="feehead">Fee</span>
                <span class="feehead">Amount (&#8358;)</span>
                <span class="feehead">Paid to</span>
                <span class="feehead">Refundable</span>

                @foreach ($fees as $i => $fee)
                    <input name="fees[{{ $i }}][label]" class="finput" value="{{ $fee['label'] ?? '' }}" placeholder="Agency fee">
                    <input name="fees[{{ $i }}][amount]" class="finput" type="number" step="1" min="0" value="{{ $fee['amount'] ?? '' }}">
                    <input name="fees[{{ $i }}][payee]" class="finput" value="{{ $fee['payee'] ?? '' }}" placeholder="Agent">
                    <label class="checkline nowrap">
                        <input type="hidden" name="fees[{{ $i }}][is_refundable]" value="0">
                        <input type="checkbox" name="fees[{{ $i }}][is_refundable]" value="1" @checked($fee['is_refundable'] ?? false)>
                        <span>Yes</span>
                    </label>
                @endforeach

                @for ($i = count($fees); $i < count($fees) + 3; $i++)
                    <input name="fees[{{ $i }}][label]" class="finput" placeholder="Service charge">
                    <input name="fees[{{ $i }}][amount]" class="finput" type="number" step="1" min="0">
                    <input name="fees[{{ $i }}][payee]" class="finput" placeholder="Estate management">
                    <label class="checkline nowrap">
                        <input type="hidden" name="fees[{{ $i }}][is_refundable]" value="0">
                        <input type="checkbox" name="fees[{{ $i }}][is_refundable]" value="1">
                        <span>Yes</span>
                    </label>
                @endfor
            </div>
        </section>

        <section class="formsec formsec-required">
            <h2>Title documents <span class="reqflag">At least one required</span></h2>
            <p class="secblurb">
                Declare what exists and what is still in progress. Agentpro shows this
                as <em>lister-declared</em> and makes no claim about legal validity
                unless RealSure verification has been completed.
            </p>

            @foreach ($titleTypes as $group => $types)
                <h3 class="titlegroup">{{ $group }}</h3>
                <div class="titlegrid">
                    @foreach ($types as $key => $label)
                        @php $current = old('titles.'.$key, $selectedTitles[$key] ?? 'none'); @endphp
                        <div class="titleopt">
                            <span class="titlenm">{{ $label }}</span>
                            <select name="titles[{{ $key }}]" class="finput finput-sm">
                                <option value="none" @selected($current === 'none')>—</option>
                                <option value="available" @selected($current === 'available')>Available</option>
                                <option value="in_progress" @selected($current === 'in_progress')>In progress</option>
                            </select>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </section>

        <section class="formsec">
            <h2>Amenities</h2>
            <div class="amengrid">
                @foreach ($amenities as $amenity)
                    <label class="checkline">
                        <input type="checkbox" name="amenities[]" value="{{ $amenity->id }}"
                               @checked(in_array($amenity->id, old('amenities', $selectedAmenities), false))>
                        <span>{{ $amenity->name }}</span>
                    </label>
                @endforeach
            </div>
        </section>

        <div class="formactions">
            <button type="submit" class="btn btn-blue">{{ $isEdit ? 'Save changes' : 'Save draft' }}</button>
            <a href="{{ route('lister.dashboard') }}" class="btn btn-ghost">Cancel</a>
            @if ($isEdit)
                <p class="formnote">Drafts are private. Submit for review from your dashboard when the listing is complete.</p>
            @endif
        </div>
    </form>
</div>
@endsection
