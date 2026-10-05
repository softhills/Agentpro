/*
 * Pinning a property on the listing form (FR-M5-02).
 *
 * The form asked a lister for a latitude and a longitude and refused to save
 * without them. Those are not things anybody knows about their own house: the
 * numbers come from a phone, and the ones on the document a land seller
 * actually holds — a survey plan — are eastings and northings in metres, which
 * the validator rejects with a message about degrees. Listings were being
 * abandoned at that field.
 *
 * So the map writes the fields. The fields stay on the form, visible and
 * editable, and they remain the thing that is submitted: this is an easier way
 * to fill them in, not a replacement for them. Nothing here is required to save
 * a listing — with no JavaScript, no Leaflet, or no tiles, the two boxes work
 * exactly as they did, which also keeps the form usable by anyone pinning a
 * property with a keyboard or a screen reader.
 */
(function () {
    'use strict';

    var root = document.getElementById('pick-map');

    if (!root || typeof L === 'undefined') {
        return;
    }

    var config   = JSON.parse(root.dataset.config);
    var latInput = document.getElementById('lat-field');
    var lngInput = document.getElementById('lng-field');
    var areaInput = document.getElementById('area_id');
    var readout  = document.querySelector('[data-pick-readout]');
    var locate   = document.querySelector('[data-pick-here]');

    if (!latInput || !lngInput) {
        return;
    }

    /* Seven decimal places is what the column holds. Six is about a tenth of a
       metre, which is already far finer than a pin dropped with a thumb, so the
       extra digit would be inventing precision. */
    function round(value) {
        return Number(value.toFixed(6));
    }

    function reading(input) {
        var value = parseFloat(input.value);

        return isNaN(value) ? null : value;
    }

    function pinned() {
        var lat = reading(latInput);
        var lng = reading(lngInput);

        // Zero is a real coordinate but not one in this market — it is the
        // Atlantic, several hundred kilometres south of Lagos — and an empty
        // field parses to NaN, not 0. Both are "no pin yet".
        return lat === null || lng === null ? null : [lat, lng];
    }

    var map = L.map(root, {
        center: pinned() || [config.lat, config.lng],
        zoom: pinned() ? 17 : config.zoom,
        maxZoom: config.maxZoom,
        // Same reasoning as the listing page: this map sits in the middle of a
        // long form, and one that swallows the scroll gesture is one you cannot
        // scroll past.
        scrollWheelZoom: false
    });

    L.tileLayer(config.tileUrl, {
        attribution: config.attribution,
        maxZoom: config.maxZoom,
        tileSize: config.tileSize || 256,
        zoomOffset: config.zoomOffset || 0
    }).addTo(map);

    var marker = null;

    function describe(point) {
        if (!readout) {
            return;
        }

        readout.textContent = point
            ? 'Pinned at ' + point[0].toFixed(6) + ', ' + point[1].toFixed(6) + '. Drag the pin to adjust it.'
            : 'Tap the map where the property is. The two boxes below fill in themselves.';
    }

    /*
     * The pin is drawn in CSS, the same way the search map draws its own.
     * Leaflet's default marker is an <img> out of an images/ folder that is not
     * vendored here — using it puts a broken image on the form, which is a poor
     * advertisement for a page that is asking somebody to trust the map.
     */
    var icon = L.divIcon({
        className: '',
        html: '<span class="pickpin" aria-hidden="true"></span>',
        iconSize: [22, 22],
        iconAnchor: [11, 22]
    });

    /* The marker is draggable, because the first tap is rarely exact and
       dragging is how a person says "a bit left" on a phone. */
    function place(point, write) {
        if (marker) {
            marker.setLatLng(point);
        } else {
            marker = L.marker(point, { icon: icon, draggable: true, autoPan: true }).addTo(map);
            marker.on('drag dragend', function (event) {
                var dragged = event.target.getLatLng();
                fill([round(dragged.lat), round(dragged.lng)]);
            });
        }

        if (write) {
            fill([round(point[0]), round(point[1])]);
        } else {
            describe(point);
        }
    }

    function fill(point) {
        latInput.value = point[0];
        lngInput.value = point[1];

        /*
         * Dispatched so anything else listening to these fields hears it, and
         * so the browser treats them as edited — a value set from script alone
         * does not fire input, which is the classic way an autosave or a
         * validation hint misses a change it was watching for.
         */
        latInput.dispatchEvent(new Event('input', { bubbles: true }));
        lngInput.dispatchEvent(new Event('input', { bubbles: true }));

        describe(point);
    }

    if (pinned()) {
        place(pinned(), false);
    }

    describe(pinned());

    map.on('click', function (event) {
        place([event.latlng.lat, event.latlng.lng], true);
    });

    /*
     * Typed into by hand, the fields still lead: somebody pasting a pair out of
     * Google Maps should see the pin move to meet them rather than watch the
     * map disagree with the form.
     */
    function follow() {
        var point = pinned();

        if (!point || Math.abs(point[0]) > 90 || Math.abs(point[1]) > 180) {
            return;
        }

        place(point, false);
        map.setView(point, Math.max(map.getZoom(), 15));
    }

    latInput.addEventListener('change', follow);
    lngInput.addEventListener('change', follow);

    /*
     * Changing the area recentres an empty map, so a lister listing in Enugu
     * does not have to drag there from Lagos. Only while nothing is pinned —
     * once there is a marker, the lister has said where the property is and the
     * map has no business moving it.
     */
    if (areaInput) {
        areaInput.addEventListener('change', function () {
            var centre = config.areas[areaInput.value];

            if (centre && !marker) {
                map.setView([centre.lat, centre.lng], centre.zoom);
            }
        });
    }

    /*
     * Most of these listings are put up by somebody standing at the property,
     * which makes the phone the most accurate thing in the room. Shown only
     * where the browser offers it, and never asked for on load: a permission
     * prompt nobody invited is a permission prompt people deny for ever.
     */
    if (locate && navigator.geolocation) {
        locate.hidden = false;

        locate.addEventListener('click', function () {
            locate.disabled = true;
            locate.textContent = 'Finding you…';

            navigator.geolocation.getCurrentPosition(function (position) {
                var point = [position.coords.latitude, position.coords.longitude];

                place(point, true);
                map.setView(point, 18);
                locate.disabled = false;
                locate.textContent = 'Use my current location';
            }, function () {
                locate.disabled = false;
                locate.textContent = 'Use my current location';

                if (readout) {
                    readout.textContent = 'Your phone would not give us a location. Tap the map instead.';
                }
            }, { enableHighAccuracy: true, timeout: 10000 });
        });
    }
})();
