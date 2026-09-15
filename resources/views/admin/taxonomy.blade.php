@extends('layouts.admin')

@section('title', 'Amenities & areas — Agentpro admin')
@section('admin_title', 'Amenities & areas')
@section('admin_lede', 'The lists listers choose from and seekers filter by. Slugs are what saved searches and shared links hold, so changing one carries them along rather than breaking them.')

@section('admin_content')
@php
    $amenityCount = $amenities->flatten()->count();
@endphp

<div class="metricgrid metricgrid-tight">
    <x-metric label="Amenities" :value="$amenityCount"
              :note="$amenities->count().' '.Str::plural('group', $amenities->count())"
              caption="FR-M5-09 · the Nigerian set" />
    <x-metric label="Areas" :value="$areas->count()"
              :note="$areas->where('is_scan_coverage', true)->count().' open for 3D capture'"
              caption="coverage is set on Coverage & capacity" />
    <x-metric label="Unused amenities" :value="$amenities->flatten()->where('properties_count', 0)->count()"
              note="on no listing yet" caption="safe to delete" />
</div>

{{-- ------------------------------------------------------------- amenities --}}

<section class="taxblock">
    <h2>Amenities</h2>
    <p class="panelhint">
        Listers pick from this list; seekers filter on the ones marked filterable.
        A new amenity appears on the next listing anyone edits — nothing needs deploying.
    </p>

    <details class="addbox">
        <summary class="btn btn-brand btn-sm">Add an amenity</summary>
        <form method="POST" action="{{ route('admin.amenities.store') }}" class="taxform">
            @csrf
            <div class="fieldset">
                <label class="flabel" for="amenity_name">Name</label>
                <input id="amenity_name" name="name" class="finput" maxlength="60" required
                       placeholder="Borehole" value="{{ old('name') }}">
            </div>
            <div class="fieldset">
                <label class="flabel" for="amenity_group">Group</label>
                <input id="amenity_group" name="group" class="finput" maxlength="40" required
                       list="amenity-groups" placeholder="utilities" value="{{ old('group') }}">
                <datalist id="amenity-groups">
                    @foreach ($groups as $group)
                        <option value="{{ $group }}"></option>
                    @endforeach
                </datalist>
            </div>
            <div class="fieldset">
                <label class="flabel" for="amenity_slug">Slug</label>
                <input id="amenity_slug" name="slug" class="finput" maxlength="60"
                       placeholder="left blank, made from the name" value="{{ old('slug') }}">
                <span class="fhint">Used in filter links. Hard to change later — it is worth getting right now.</span>
            </div>
            <label class="fcheck">
                <input type="hidden" name="is_filterable" value="0">
                <input type="checkbox" name="is_filterable" value="1" checked>
                <span>Show as a search filter</span>
            </label>
            <button class="btn btn-brand btn-sm">Add</button>
        </form>
    </details>

    @foreach ($amenities as $group => $items)
        <h3 class="taxgroup">{{ Str::headline($group) }} <em>{{ $items->count() }}</em></h3>

        <div class="tablewrap">
        <table class="admintable">
            <thead><tr><th>Amenity</th><th>Slug</th><th>On listings</th><th>Filter</th><th>Order</th><th></th></tr></thead>
            <tbody>
            @foreach ($items as $amenity)
                <tr>
                    <td><strong>{{ $amenity->name }}</strong></td>
                    <td class="mono">{{ $amenity->slug }}</td>
                    <td class="num">{{ $amenity->properties_count }}</td>
                    <td>
                        @if ($amenity->is_filterable)
                            <span class="ostate ostate-paid">filterable</span>
                        @else
                            <span class="sub">display only</span>
                        @endif
                    </td>
                    <td class="num sub">{{ $amenity->sort_order }}</td>
                    <td class="taxactions">
                        <details class="refundbox">
                            <summary class="linkbtn">Edit</summary>
                            <form method="POST" action="{{ route('admin.amenities.update', $amenity) }}" class="taxform taxform-tight">
                                @csrf @method('PUT')
                                <input name="name" class="finput finput-sm" value="{{ $amenity->name }}" maxlength="60" required>
                                <input name="group" class="finput finput-sm" value="{{ $amenity->group }}" maxlength="40" required list="amenity-groups">
                                <input name="slug" class="finput finput-sm mono" value="{{ $amenity->slug }}" maxlength="60" required>
                                <input name="sort_order" type="number" class="finput finput-sm" value="{{ $amenity->sort_order }}" min="0" max="9999">
                                <label class="fcheck">
                                    <input type="hidden" name="is_filterable" value="0">
                                    <input type="checkbox" name="is_filterable" value="1" @checked($amenity->is_filterable)>
                                    <span>Filterable</span>
                                </label>
                                <button class="btn btn-ghost btn-sm">Save</button>
                            </form>
                        </details>

                        {{--
                            Merge rather than delete is the operation an operator
                            almost always wants here: duplicates like "Borehole"
                            and "Bore hole" are what this list accumulates.
                        --}}
                        <details class="refundbox">
                            <summary class="linkbtn">Merge</summary>
                            <form method="POST" action="{{ route('admin.amenities.merge', $amenity) }}" class="taxform taxform-tight">
                                @csrf
                                <select name="into" class="finput finput-sm" required>
                                    <option value="">Merge into…</option>
                                    @foreach ($allAmenities as $option)
                                        @continue($option->id === $amenity->id)
                                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                                    @endforeach
                                </select>
                                <span class="fhint">
                                    Listings keep the amenity; only the duplicate name goes.
                                </span>
                                <button class="btn btn-ghost btn-sm">Merge</button>
                            </form>
                        </details>

                        @if ($amenity->properties_count === 0)
                            <form method="POST" action="{{ route('admin.amenities.destroy', $amenity) }}">
                                @csrf @method('DELETE')
                                <button class="linkbtn linkbtn-bad">Delete</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    @endforeach
</section>

{{-- ----------------------------------------------------------------- areas --}}

<section class="taxblock">
    <h2>Areas</h2>
    <p class="panelhint">
        {{-- The negation is plain text on purpose. Wrapped in <em> it was being
             dropped by the accessibility tree, which turned "does not open it"
             into "does open it" — the opposite instruction. --}}
        The places a seeker can search by. Adding one here does not open it for
        3D capture: that commits the field team, and is set on
        <a href="{{ route('admin.operations') }}">Coverage &amp; capacity</a>.
    </p>

    <details class="addbox">
        <summary class="btn btn-brand btn-sm">Add an area</summary>
        <form method="POST" action="{{ route('admin.areas.store') }}" class="taxform">
            @csrf
            <div class="fieldset">
                <label class="flabel" for="area_name">Name</label>
                <input id="area_name" name="name" class="finput" maxlength="80" required placeholder="Gbagada">
            </div>
            <div class="fieldset">
                <label class="flabel" for="area_city">City</label>
                <input id="area_city" name="city" class="finput" maxlength="80" required placeholder="Lagos">
            </div>
            <div class="fieldset">
                <label class="flabel" for="area_state">State</label>
                <input id="area_state" name="state" class="finput" maxlength="80" required placeholder="Lagos">
            </div>
            <div class="fieldset">
                <label class="flabel" for="area_lat">Centre latitude</label>
                <input id="area_lat" name="centroid_lat" class="finput" placeholder="6.5540">
                <span class="fhint">Where the map opens for this area. Blank falls back to the city view.</span>
            </div>
            <div class="fieldset">
                <label class="flabel" for="area_lng">Centre longitude</label>
                <input id="area_lng" name="centroid_lng" class="finput" placeholder="3.3890">
            </div>
            <div class="fieldset">
                <label class="flabel" for="area_zoom">Default zoom</label>
                <input id="area_zoom" name="default_zoom" type="number" class="finput" value="14" min="8" max="18">
            </div>
            <button class="btn btn-brand btn-sm">Add</button>
        </form>
    </details>

    <div class="tablewrap">
    <table class="admintable">
        <thead><tr><th>Area</th><th>Slug</th><th>Where</th><th>Listings</th><th>3D capture</th><th></th></tr></thead>
        <tbody>
        @forelse ($areas as $area)
            <tr>
                <td><strong>{{ $area->name }}</strong></td>
                <td class="mono">{{ $area->slug }}</td>
                <td>{{ $area->city }}<span class="sub">{{ $area->state }}</span></td>
                <td class="num">
                    {{ $area->live_count }} live
                    @if ($area->properties_count !== $area->live_count)
                        <span class="sub">{{ $area->properties_count }} in total</span>
                    @endif
                </td>
                <td>
                    @if ($area->is_scan_coverage)
                        <span class="ostate ostate-paid">open</span>
                    @else
                        <span class="sub">closed</span>
                    @endif
                </td>
                <td class="taxactions">
                    <details class="refundbox">
                        <summary class="linkbtn">Edit</summary>
                        <form method="POST" action="{{ route('admin.areas.update', $area) }}" class="taxform taxform-tight">
                            @csrf @method('PUT')
                            <input name="name" class="finput finput-sm" value="{{ $area->name }}" maxlength="80" required>
                            <input name="slug" class="finput finput-sm mono" value="{{ $area->slug }}" maxlength="80" required>
                            <input name="city" class="finput finput-sm" value="{{ $area->city }}" maxlength="80" required>
                            <input name="state" class="finput finput-sm" value="{{ $area->state }}" maxlength="80" required>
                            <input name="centroid_lat" class="finput finput-sm" value="{{ $area->centroid_lat }}" placeholder="latitude">
                            <input name="centroid_lng" class="finput finput-sm" value="{{ $area->centroid_lng }}" placeholder="longitude">
                            <input name="default_zoom" type="number" class="finput finput-sm" value="{{ $area->default_zoom }}" min="8" max="18">
                            <button class="btn btn-ghost btn-sm">Save</button>
                        </form>
                    </details>

                    <details class="refundbox">
                        <summary class="linkbtn">Merge</summary>
                        <form method="POST" action="{{ route('admin.areas.merge', $area) }}" class="taxform taxform-tight">
                            @csrf
                            <select name="into" class="finput finput-sm" required>
                                <option value="">Merge into…</option>
                                @foreach ($allAreas as $option)
                                    @continue($option->id === $area->id)
                                    <option value="{{ $option->id }}">{{ $option->name }} — {{ $option->city }}</option>
                                @endforeach
                            </select>
                            <span class="fhint">Listings, capture slots and saved searches all move across.</span>
                            <button class="btn btn-ghost btn-sm">Merge</button>
                        </form>
                    </details>

                    @if ($area->properties_count === 0 && $area->technician_slots_count === 0)
                        <form method="POST" action="{{ route('admin.areas.destroy', $area) }}">
                            @csrf @method('DELETE')
                            <button class="linkbtn linkbtn-bad">Delete</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="fhint">No areas yet.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</section>

{{-- ---------------------------------------------------------- vocabularies --}}

<section class="taxblock">
    <h2>Fixed vocabularies</h2>
    <p class="panelhint">
        These are not editable here, and that is deliberate. Title types
        carry legal weight, and reason codes are the raw material for telling the
        business which part of the submission flow is failing listers — so changing one
        goes through review and a release, not a text box. They are listed because an
        operator writing a rejection needs to know what the codes are without reading
        the source.
    </p>

    <div class="vocabgrid">
        @foreach ($vocabularies as $title => $terms)
            <div class="vocab">
                <h3>{{ $title }} <em>{{ count($terms) }}</em></h3>
                <dl>
                    @foreach ($terms as $key => $label)
                        <div><dt class="mono">{{ $key }}</dt><dd>{{ $label }}</dd></div>
                    @endforeach
                </dl>
            </div>
        @endforeach
    </div>
</section>
@endsection
