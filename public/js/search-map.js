/**
 * Map-first search (FR-M5-02).
 *
 * The map is the primary search surface, so three things have to stay in step:
 * what the map shows, what the result list shows, and what the URL says. The URL
 * is the source of truth — a seeker who pans to Ikoyi and sends someone that
 * link should be sending Ikoyi.
 *
 * Leaflet rather than a vector-tile renderer, deliberately. The tiles are
 * raster, so a WebGL engine buys nothing but weight: 145KB against 918KB on a
 * page with a 1.2MB budget (NFR-02), and no WebGL requirement on the mid-tier
 * Android NFR-01 is written for.
 *
 * No framework. The result list stays server-rendered and is swapped as HTML,
 * so there is one set of card markup rather than a second copy in JavaScript.
 */
(function () {
    var root = document.getElementById('search-map');

    if (!root || typeof L === 'undefined') {
        return;
    }

    var config = JSON.parse(root.dataset.config);
    var resultsEl = document.getElementById('search-results');

    var map = L.map(root, {
        center: [config.center.lat, config.center.lng],
        zoom: config.zoom,
        maxZoom: config.maxZoom,
        zoomControl: false,
        // Scroll should scroll the page. Hijacking the wheel is the fastest way
        // to make a two-pane layout infuriating on a laptop; the map zooms from
        // its own controls instead.
        scrollWheelZoom: false,
    });

    L.control.zoom({ position: 'topright' }).addTo(map);

    L.tileLayer(config.tileUrl, {
        maxZoom: config.maxZoom,
        attribution: config.attribution,
    }).addTo(map);

    var layer = L.layerGroup().addTo(map);
    var inFlight = null;
    var moveTimer = null;

    function currentQuery() {
        // Start from the filters already in the URL, so the filter bar and the
        // map never disagree about what is being searched.
        var params = new URLSearchParams(window.location.search);
        var b = map.getBounds();

        params.set('south', b.getSouth().toFixed(6));
        params.set('west', b.getWest().toFixed(6));
        params.set('north', b.getNorth().toFixed(6));
        params.set('east', b.getEast().toFixed(6));
        params.set('zoom', map.getZoom());
        params.delete('page');

        return params;
    }

    function priceLabel(value) {
        if (!value) return '—';
        var n = Number(value);
        if (n >= 1e9) return '₦' + (n / 1e9).toFixed(1).replace(/\.0$/, '') + 'B';
        if (n >= 1e6) return '₦' + (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
        if (n >= 1e3) return '₦' + Math.round(n / 1e3) + 'K';
        return '₦' + n;
    }

    function addPin(marker) {
        var icon = L.divIcon({
            className: '',
            html: '<button type="button" class="mappin' + (marker.realsure ? ' sure-pin' : '') + '"' +
                  ' data-pin="' + marker.id + '">' + priceLabel(marker.price) + '</button>',
            iconSize: null,
        });

        L.marker([marker.lat, marker.lng], { icon: icon, keyboard: false }).addTo(layer);
    }

    function addCluster(marker) {
        var label = marker.count === 1 ? '1 listing here' : marker.count + ' listings here';

        var icon = L.divIcon({
            className: '',
            html: '<button type="button" class="mapcluster"' +
                  ' data-lat="' + marker.lat + '" data-lng="' + marker.lng + '"' +
                  ' aria-label="' + label + ' — zoom in">' + marker.count + '</button>',
            iconSize: null,
        });

        L.marker([marker.lat, marker.lng], { icon: icon, keyboard: false }).addTo(layer);
    }

    /*
     * Marker clicks are handled by delegation on the map container rather than
     * by Leaflet's own marker click event.
     *
     * A divIcon whose content is a real <button> — which it should be, so the
     * markers are focusable and reachable by keyboard — does not reach Leaflet's
     * own marker handler, and Leaflet in turn calls stopPropagation on marker
     * clicks so they never bubble back out to the container either. The click
     * lands nowhere and the marker silently does nothing.
     *
     * Listening in the capture phase sidesteps both: capture runs from the
     * document down, before anything has had a chance to stop propagation.
     */
    root.addEventListener('click', function (event) {
        var cluster = event.target.closest('.mapcluster');

        if (cluster) {
            // A cluster answers "what is over there", not "show me one of these",
            // so it zooms rather than opening anything.
            map.setView(
                [parseFloat(cluster.dataset.lat), parseFloat(cluster.dataset.lng)],
                Math.min(map.getZoom() + 2, config.maxZoom),
                // Leaflet's zoom animation runs on requestAnimationFrame, which
                // browsers pause in a hidden document. A click that arrives
                // while the tab is backgrounded would otherwise be swallowed
                // and the map would simply never move.
                { animate: !document.hidden }
            );
            return;
        }

        var pin = event.target.closest('.mappin');

        if (pin) {
            var card = document.querySelector('.card[data-pin="' + CSS.escape(pin.dataset.pin) + '"]');
            if (card) {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                card.classList.add('card-flash');
                setTimeout(function () { card.classList.remove('card-flash'); }, 1200);
            }
        }
    }, true);

    function pairing() {
        document.querySelectorAll('.card[data-pin]').forEach(function (card) {
            card.addEventListener('mouseenter', function () { highlight(card.dataset.pin, true); });
            card.addEventListener('mouseleave', function () { highlight(card.dataset.pin, false); });
        });
    }

    function highlight(id, on) {
        var pin = document.querySelector('.mappin[data-pin="' + CSS.escape(id) + '"]');
        if (pin) pin.classList.toggle('act', on);
    }

    function refresh() {
        var params = currentQuery();

        // Updated before the fetch, so a slow response never leaves the address
        // bar describing a different viewport than the one on screen.
        window.history.replaceState({}, '', window.location.pathname + '?' + params.toString());

        if (inFlight) inFlight.abort();
        inFlight = new AbortController();

        root.classList.add('map-loading');

        Promise.all([
            fetch(config.pinsUrl + '?' + params.toString(), { signal: inFlight.signal })
                .then(function (r) { return r.ok ? r.json() : { markers: [] }; }),
            fetch(config.resultsUrl + '?' + params.toString() + '&fragment=1', {
                signal: inFlight.signal,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            }).then(function (r) { return r.ok ? r.text() : null; }),
        ]).then(function (out) {
            var pins = out[0];
            var html = out[1];

            layer.clearLayers();
            (pins.markers || []).forEach(pins.mode === 'clusters' ? addCluster : addPin);

            if (html !== null && resultsEl) {
                resultsEl.innerHTML = html;
                pairing();
            }
        }).catch(function (error) {
            // Aborting is the expected outcome of panning quickly, not a failure.
            if (error.name !== 'AbortError') {
                console.error('search refresh failed', error);
            }
        }).finally(function () {
            root.classList.remove('map-loading');
        });
    }

    map.on('moveend', function () {
        clearTimeout(moveTimer);
        moveTimer = setTimeout(refresh, 350);
    });

    refresh();
    pairing();
})();
