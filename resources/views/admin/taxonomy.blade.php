@extends('layouts.admin')

@section('title', 'Amenities & areas — Agentpro admin')
@section('admin_title', 'Amenities & areas')
@section('admin_lede', 'The lists listers choose from and seekers filter by. Slugs are what saved searches and shared links hold, so changing one carries them along rather than breaking them.')

@section('admin_content')
@php
    $amenityCount = $amenities->flatten()->count();
    $unusedCount  = $amenities->flatten()->where('properties_count', 0)->count();

    /*
     * The query orders by state, then city, then name, so the areas in a city
     * are already contiguous and grouping preserves that order.
     *
     * This replaces the "Where" column, which repeated "Lagos / Lagos" down
     * fifteen rows to say something that belongs over the group once. The
     * column was also the widest thing in the table and pushed the actions off
     * the right-hand edge.
     */
    $areasByCity = $areas->groupBy(fn ($area) => $area->city === $area->state
        ? $area->city                      // Lagos, Lagos State: once is enough
        : $area->city.', '.$area->state);

    /*
     * Both add forms have a field called "name", and old() cannot tell which
     * of the two a flashed value came from — a failed amenity would reappear
     * inside the area form. The error bags can, so repopulation is gated on
     * the bag belonging to the form that actually failed.
     */
    $amenityFailed = $errors->hasBag('amenity');
    $areaFailed    = $errors->hasBag('area');
@endphp

<div class="metricgrid metricgrid-tight">
    <x-metric label="Amenities" :value="$amenityCount"
              :note="$amenities->count().' '.Str::plural('group', $amenities->count())"
              caption="FR-M5-09 · the Nigerian set" />
    <x-metric label="Areas" :value="$areas->count()"
              :note="$areas->where('is_scan_coverage', true)->count().' open for 3D capture'"
              caption="coverage is set on Coverage & capacity" />
    <x-metric label="Unused amenities" :value="$unusedCount"
              note="on no listing yet" caption="safe to delete" />
</div>

{{--
    This page is three lists end to end, and the one an operator wants is
    rarely the first. Adding an area meant scrolling past seventeen amenities
    in seven tables to find the control. Same sticky index as the listing form,
    for the same reason: the sections stay one click apart however far down the
    page you are.
--}}
<nav class="formnav" aria-label="Sections of this page">
    <a href="#tax-amenities">Amenities <span class="n">{{ $amenityCount }}</span></a>
    <a href="#tax-areas">Areas <span class="n">{{ $areas->count() }}</span></a>
    <a href="#tax-vocab">Fixed vocabularies <span class="n">{{ count($vocabularies) }}</span></a>
</nav>

{{-- ------------------------------------------------------------- amenities --}}

<section class="taxblock" id="tax-amenities">
    <h2>Amenities</h2>
    <p class="panelhint">
        Listers pick from this list; seekers filter on the ones marked filterable.
        A new amenity appears on the next listing anyone edits — nothing needs deploying.
    </p>

    {{--
        Open, rather than folded behind a button. Adding to these lists is what
        this screen is for, and a form that has to be summoned before it can be
        read is a form that hides its own errors when the submission bounces.
        Laid out across the panel instead of down it, so it costs one row.
    --}}
    <form method="POST" action="{{ route('admin.amenities.store') }}" class="taxadd">
        @csrf
        <p class="taxadd-title">Add an amenity</p>

        <div class="fieldset">
            <label class="flabel" for="amenity_name">Name</label>
            <input id="amenity_name" name="name" maxlength="60" required placeholder="Borehole"
                   value="{{ $amenityFailed ? old('name') : '' }}"
                   class="finput finput-sm @error('name', 'amenity') has-error @enderror">
            @error('name', 'amenity') <p class="ferror">{{ $message }}</p> @enderror
        </div>

        <div class="fieldset">
            <label class="flabel" for="amenity_group">Group</label>
            <input id="amenity_group" name="group" maxlength="40" required list="amenity-groups"
                   placeholder="utilities" value="{{ $amenityFailed ? old('group') : '' }}"
                   class="finput finput-sm @error('group', 'amenity') has-error @enderror">
            @error('group', 'amenity') <p class="ferror">{{ $message }}</p> @enderror
        </div>

        <div class="fieldset">
            <label class="flabel" for="amenity_slug">Slug</label>
            <input id="amenity_slug" name="slug" maxlength="60"
                   placeholder="made from the name" value="{{ $amenityFailed ? old('slug') : '' }}"
                   class="finput finput-sm mono @error('slug', 'amenity') has-error @enderror">
            @error('slug', 'amenity') <p class="ferror">{{ $message }}</p> @enderror
        </div>

        <label class="fcheck">
            <input type="hidden" name="is_filterable" value="0">
            <input type="checkbox" name="is_filterable" value="1" checked>
            {{-- Short, so it does not wrap to two lines in its column; the
                 note under the row says what filterable means. --}}
            <span>Show as a filter</span>
        </label>

        <button class="btn btn-brand btn-sm">Add amenity</button>

        <p class="taxadd-note">
            A filterable amenity appears in the search filters; the rest are shown on a
            listing but cannot be searched on. Left blank, the slug is made from the
            name — it is what filter links and saved searches hold, so it is worth
            getting right now.
        </p>
    </form>

    {{-- The group list shared by the add form and every inline edit form. --}}
    <datalist id="amenity-groups">
        @foreach ($groups as $group)
            <option value="{{ $group }}"></option>
        @endforeach
    </datalist>

    {{--
        One table with a heading row per group, not seven tables. Seven sets of
        column headers for seventeen rows is more header than data, and the
        columns were free to drift out of alignment between one group and the
        next, so nothing could be compared down the page.
    --}}
    <div class="tablewrap">
    <table class="admintable">
        <thead>
            <tr>
                <th>Amenity</th><th>Slug</th><th>On listings</th>
                <th>Filter</th><th>Order</th><th class="ta-r">Actions</th>
            </tr>
        </thead>

        @forelse ($amenities as $group => $items)
            <tbody>
                <tr class="grouprow">
                    <th colspan="6" scope="colgroup">
                        {{ Str::headline($group) }} <em>{{ $items->count() }}</em>
                    </th>
                </tr>

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
                        <td class="num ordernum">{{ $amenity->sort_order }}</td>
                        <td class="actionscell">
                            <div class="taxactions">
                            <details class="refundbox">
                                <summary class="linkbtn">Edit</summary>
                                <form method="POST" action="{{ route('admin.amenities.update', $amenity) }}" class="taxform taxform-tight">
                                    @csrf @method('PUT')
                                    {{-- Labelled, because four unlabelled boxes
                                         holding a name, a group, a slug and a
                                         number are told apart only by guessing
                                         at their contents. --}}
                                    <label class="taxfield"><span>Name</span>
                                        <input name="name" class="finput finput-sm" value="{{ $amenity->name }}" maxlength="60" required>
                                    </label>
                                    <label class="taxfield"><span>Group</span>
                                        <input name="group" class="finput finput-sm" value="{{ $amenity->group }}" maxlength="40" required list="amenity-groups">
                                    </label>
                                    <label class="taxfield"><span>Slug</span>
                                        <input name="slug" class="finput finput-sm mono" value="{{ $amenity->slug }}" maxlength="60" required>
                                    </label>
                                    <label class="taxfield taxfield-half"><span>Order</span>
                                        <input name="sort_order" type="number" class="finput finput-sm" value="{{ $amenity->sort_order }}" min="0" max="9999">
                                    </label>
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
                                    <label class="taxfield"><span>Merge “{{ $amenity->name }}” into</span>
                                        <select name="into" class="finput finput-sm" required>
                                            <option value="">Choose an amenity…</option>
                                            @foreach ($allAmenities as $option)
                                                @continue($option->id === $amenity->id)
                                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                                            @endforeach
                                        </select>
                                    </label>
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
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        @empty
            <tbody>
                <tr><td colspan="6" class="fhint">No amenities yet — add the first one above.</td></tr>
            </tbody>
        @endforelse
    </table>
    </div>
</section>

{{-- ----------------------------------------------------------------- areas --}}

<section class="taxblock" id="tax-areas">
    <h2>Areas</h2>
    <p class="panelhint">
        {{-- The negation is plain text on purpose. Wrapped in <em> it was being
             dropped by the accessibility tree, which turned "does not open it"
             into "does open it" — the opposite instruction. --}}
        The places a seeker can search by. Adding one here does not open it for
        3D capture: that commits the field team, and is set on
        <a href="{{ route('admin.operations') }}">Coverage &amp; capacity</a>.
    </p>

    <form method="POST" action="{{ route('admin.areas.store') }}" class="taxadd">
        @csrf
        <p class="taxadd-title">Add an area</p>

        <div class="fieldset">
            <label class="flabel" for="area_name">Name</label>
            <input id="area_name" name="name" maxlength="80" required placeholder="Gbagada"
                   value="{{ $areaFailed ? old('name') : '' }}"
                   class="finput finput-sm @error('name', 'area') has-error @enderror">
            @error('name', 'area') <p class="ferror">{{ $message }}</p> @enderror
        </div>

        <div class="fieldset">
            <label class="flabel" for="area_city">City</label>
            <input id="area_city" name="city" maxlength="80" required placeholder="Lagos"
                   value="{{ $areaFailed ? old('city') : '' }}"
                   class="finput finput-sm @error('city', 'area') has-error @enderror">
            @error('city', 'area') <p class="ferror">{{ $message }}</p> @enderror
        </div>

        <div class="fieldset">
            <label class="flabel" for="area_state">State</label>
            <input id="area_state" name="state" maxlength="80" required placeholder="Lagos"
                   value="{{ $areaFailed ? old('state') : '' }}"
                   class="finput finput-sm @error('state', 'area') has-error @enderror">
            @error('state', 'area') <p class="ferror">{{ $message }}</p> @enderror
        </div>

        <div class="fieldset">
            <label class="flabel" for="area_lat">Centre latitude</label>
            <input id="area_lat" name="centroid_lat" placeholder="6.5540"
                   value="{{ $areaFailed ? old('centroid_lat') : '' }}"
                   class="finput finput-sm @error('centroid_lat', 'area') has-error @enderror">
            @error('centroid_lat', 'area') <p class="ferror">{{ $message }}</p> @enderror
        </div>

        <div class="fieldset">
            <label class="flabel" for="area_lng">Centre longitude</label>
            <input id="area_lng" name="centroid_lng" placeholder="3.3890"
                   value="{{ $areaFailed ? old('centroid_lng') : '' }}"
                   class="finput finput-sm @error('centroid_lng', 'area') has-error @enderror">
            @error('centroid_lng', 'area') <p class="ferror">{{ $message }}</p> @enderror
        </div>

        <div class="fieldset">
            <label class="flabel" for="area_zoom">Default zoom</label>
            <input id="area_zoom" name="default_zoom" type="number" min="8" max="18"
                   value="{{ $areaFailed ? old('default_zoom', 14) : 14 }}"
                   class="finput finput-sm @error('default_zoom', 'area') has-error @enderror">
            @error('default_zoom', 'area') <p class="ferror">{{ $message }}</p> @enderror
        </div>

        <button class="btn btn-brand btn-sm">Add area</button>

        <p class="taxadd-note">
            The centre point is where the map opens for this area; left blank it falls
            back to the city view. A new area is closed for 3D capture until Operations
            opens it.
        </p>
    </form>

    <div class="tablewrap">
    <table class="admintable">
        <thead>
            <tr>
                <th>Area</th><th>Slug</th><th>Listings</th>
                <th>3D capture</th><th class="ta-r">Actions</th>
            </tr>
        </thead>

        @forelse ($areasByCity as $where => $items)
            <tbody>
                <tr class="grouprow">
                    <th colspan="5" scope="colgroup">{{ $where }} <em>{{ $items->count() }}</em></th>
                </tr>

                @foreach ($items as $area)
                    <tr>
                        <td><strong>{{ $area->name }}</strong></td>
                        <td class="mono">{{ $area->slug }}</td>
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
                        <td class="actionscell">
                            <div class="taxactions">
                            <details class="refundbox">
                                <summary class="linkbtn">Edit</summary>
                                <form method="POST" action="{{ route('admin.areas.update', $area) }}" class="taxform taxform-tight">
                                    @csrf @method('PUT')
                                    <label class="taxfield"><span>Name</span>
                                        <input name="name" class="finput finput-sm" value="{{ $area->name }}" maxlength="80" required>
                                    </label>
                                    <label class="taxfield"><span>Slug</span>
                                        <input name="slug" class="finput finput-sm mono" value="{{ $area->slug }}" maxlength="80" required>
                                    </label>
                                    <label class="taxfield"><span>City</span>
                                        <input name="city" class="finput finput-sm" value="{{ $area->city }}" maxlength="80" required>
                                    </label>
                                    <label class="taxfield"><span>State</span>
                                        <input name="state" class="finput finput-sm" value="{{ $area->state }}" maxlength="80" required>
                                    </label>
                                    <label class="taxfield taxfield-half"><span>Latitude</span>
                                        <input name="centroid_lat" class="finput finput-sm" value="{{ $area->centroid_lat }}">
                                    </label>
                                    <label class="taxfield taxfield-half"><span>Longitude</span>
                                        <input name="centroid_lng" class="finput finput-sm" value="{{ $area->centroid_lng }}">
                                    </label>
                                    <label class="taxfield taxfield-half"><span>Zoom</span>
                                        <input name="default_zoom" type="number" class="finput finput-sm" value="{{ $area->default_zoom }}" min="8" max="18">
                                    </label>
                                    <button class="btn btn-ghost btn-sm">Save</button>
                                </form>
                            </details>

                            <details class="refundbox">
                                <summary class="linkbtn">Merge</summary>
                                <form method="POST" action="{{ route('admin.areas.merge', $area) }}" class="taxform taxform-tight">
                                    @csrf
                                    <label class="taxfield"><span>Merge “{{ $area->name }}” into</span>
                                        <select name="into" class="finput finput-sm" required>
                                            <option value="">Choose an area…</option>
                                            @foreach ($allAreas as $option)
                                                @continue($option->id === $area->id)
                                                <option value="{{ $option->id }}">{{ $option->name }} — {{ $option->city }}</option>
                                            @endforeach
                                        </select>
                                    </label>
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
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        @empty
            <tbody>
                <tr><td colspan="5" class="fhint">No areas yet — add the first one above.</td></tr>
            </tbody>
        @endforelse
    </table>
    </div>
</section>

{{-- ---------------------------------------------------------- vocabularies --}}

{{--
    Folded. These six lists are seventy-odd codes of reference material that
    nobody edits — they were the longest thing on the page and sat between the
    operator and the bottom of it. Open when you need to look a code up,
    closed the rest of the time.
--}}
<details class="taxblock taxfold" id="tax-vocab">
    <summary>
        <h2>Fixed vocabularies</h2>
        <span class="taxfold-note">{{ count($vocabularies) }} read-only lists</span>
    </summary>

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
</details>
@endsection
