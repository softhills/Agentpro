/**
 * The location map on a listing (FR-M5-02, FR-M5-04).
 *
 * A deliberately small sibling of search-map.js rather than a shared module.
 * The search map synchronises three things — viewport, result list and URL —
 * and none of that applies here: this map shows one pin and never moves unless
 * somebody drags it. Sharing code between them would mean carrying the
 * synchronisation machinery onto a page that has nothing to synchronise.
 *
 * Scroll-wheel zoom is off. On a phone this map sits in the middle of a long
 * page, and a map that swallows the scroll gesture is a map you cannot scroll
 * past — the single most common complaint about embedded maps. Dragging still
 * works, and the zoom controls are there for anyone who wants them.
 */
(function () {
    var root = document.getElementById('detail-map');

    if (!root || typeof L === 'undefined') {
        return;
    }

    var config = JSON.parse(root.dataset.config);

    var map = L.map(root, {
        center: [config.lat, config.lng],
        zoom: 15,
        maxZoom: config.maxZoom,
        scrollWheelZoom: false,
        // Two fingers to pan on touch, for the same reason: one finger should
        // scroll the page it is sitting in.
        dragging: !L.Browser.mobile,
        tap: false
    });

    L.tileLayer(config.tileUrl, {
        attribution: config.attribution,
        maxZoom: config.maxZoom
    }).addTo(map);

    /*
     * A circle rather than a dropped pin.
     *
     * The coordinate is what the lister typed, and in a market where street
     * addressing is unreliable (problem P3) a sharp pin claims a precision
     * nobody has verified. A soft marker says "around here", which is the
     * honest reading of the data and matches what the caption underneath says.
     */
    L.circleMarker([config.lat, config.lng], {
        radius: 11,
        color: '#3E57E3',
        weight: 3,
        fillColor: '#3E57E3',
        fillOpacity: 0.22
    }).addTo(map).bindTooltip(config.label, { direction: 'top', offset: [0, -8] });
})();
