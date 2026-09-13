/*
 * Agentpro service worker.
 *
 * Served from the site root so its scope covers the whole application — a
 * worker at /js/sw.js would only control /js/, which is the classic reason
 * push registration appears to succeed and then never delivers.
 *
 * Deliberately minimal: it does not cache anything and makes the site no more
 * offline-capable than it was. A worker is simply the only place a browser will
 * let a push be received, so this one does that job and nothing else. Caching
 * property pages would put stale prices in front of seekers, which is the exact
 * failure the freshness rules exist to prevent.
 */

self.addEventListener('install', function () {
    // Take over without waiting for existing tabs to close, so enabling push
    // works on the page the user is standing on rather than the next one.
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    var payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        // A push with a body we cannot read is still a push. Browsers require
        // a visible notification for every one received, and a worker that
        // throws here has its permission revoked after a few occurrences.
        payload = {};
    }

    var title = payload.title || 'Agentpro';

    event.waitUntil(
        self.registration.showNotification(title, {
            body: payload.body || '',
            // Same tag replaces rather than stacks, so three updates about one
            // listing are one line in the tray.
            tag: payload.tag || 'agentpro',
            renotify: false,
            icon: '/icon-192.png',
            badge: '/badge-72.png',
            data: { url: payload.url || '/' }
        })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var target = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windows) {
            // Focus an existing tab rather than opening a fourth copy of the
            // site, which is what happens by default and what people complain
            // about.
            for (var i = 0; i < windows.length; i++) {
                var client = windows[i];

                if ('focus' in client && 'navigate' in client) {
                    return client.focus().then(function (focused) {
                        return focused.navigate(target);
                    });
                }
            }

            return self.clients.openWindow(target);
        })
    );
});

/*
 * A browser can rotate a subscription on its own — a key expiring, a push
 * service migrating — and there is deliberately no handler here that
 * re-registers the new one.
 *
 * A service worker has no CSRF token and no way to get one, so re-registering
 * from here would mean exempting the subscribe endpoint from CSRF. That
 * endpoint decides *where* somebody's notifications are delivered, so an
 * exemption would let any site that can make the victim's browser issue a
 * request point their notifications at an attacker's endpoint. Not worth it for
 * a rare event that heals itself: the stale endpoint returns 410 and is pruned,
 * and the next page load re-subscribes.
 */
