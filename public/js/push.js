/*
 * Turning browser push on and off (FR-M9-08).
 *
 * Three states the interface has to tell apart, because they need different
 * words and only one of them is fixable by tapping again:
 *
 *   unsupported  this browser has no push at all
 *   denied       the user said no, and only browser settings can undo it
 *   default      never asked
 *
 * The common mistake is showing one "Enable" button for all three, so a user
 * who blocked notifications months ago taps a button that does nothing for the
 * rest of the product's life.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-push]');

    if (!root) {
        return;
    }

    var button = root.querySelector('[data-push-toggle]');
    var status = root.querySelector('[data-push-status]');
    var token = document.querySelector('meta[name="csrf-token"]');

    var supported = 'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window;

    function say(message, kind) {
        status.textContent = message;
        status.className = 'pushstatus' + (kind ? ' pushstatus-' + kind : '');
    }

    function busy(isBusy, label) {
        button.disabled = isBusy;
        button.textContent = label;
    }

    /* The applicationServerKey has to be raw bytes, not the base64url string. */
    function decodeKey(value) {
        var padded = (value + '='.repeat((4 - (value.length % 4)) % 4))
            .replace(/-/g, '+')
            .replace(/_/g, '/');
        var raw = window.atob(padded);
        var bytes = new Uint8Array(raw.length);

        for (var i = 0; i < raw.length; i++) {
            bytes[i] = raw.charCodeAt(i);
        }

        return bytes;
    }

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token ? token.content : '',
                'Accept': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        });
    }

    if (!supported) {
        button.hidden = true;
        say('This browser cannot receive push notifications. Email and your inbox still work.');
        return;
    }

    if (Notification.permission === 'denied') {
        button.hidden = true;
        say(
            'Notifications are blocked for this site in your browser settings. ' +
            'Allow them there, then reload this page.',
            'warn'
        );
        return;
    }

    var registration = null;

    navigator.serviceWorker.register('/sw.js')
        .then(function (reg) {
            registration = reg;
            return reg.pushManager.getSubscription();
        })
        .then(function (subscription) {
            if (subscription) {
                /*
                 * Re-registered on every load, not only on first subscribe.
                 * The browser keeps the subscription after site data is cleared
                 * on the server side, or after a database restore, and without
                 * this the two quietly disagree — the browser believes it is
                 * subscribed and nothing ever arrives. The endpoint is upserted,
                 * so repeating it is free.
                 */
                return post('/account/push/subscribe', subscription.toJSON())
                    .then(function () { showOn(); });
            }

            showOff();
        })
        .catch(function () {
            button.hidden = true;
            say('Push could not be set up in this browser.', 'warn');
        });

    function showOn() {
        busy(false, 'Turn off on this device');
        button.dataset.pushToggle = 'off';
        say('On for this device.', 'ok');
    }

    function showOff() {
        busy(false, 'Turn on for this device');
        button.dataset.pushToggle = 'on';
        say('Off for this device.');
    }

    button.addEventListener('click', function () {
        if (button.dataset.pushToggle === 'off') {
            busy(true, 'Turning off…');

            registration.pushManager.getSubscription()
                .then(function (subscription) {
                    if (!subscription) {
                        showOff();
                        return null;
                    }

                    var endpoint = subscription.endpoint;

                    return subscription.unsubscribe().then(function () {
                        return post('/account/push/unsubscribe', { endpoint: endpoint });
                    }).then(showOff);
                })
                .catch(function () {
                    say('Could not turn push off. Try again.', 'warn');
                    showOn();
                });

            return;
        }

        busy(true, 'Asking your browser…');

        fetch('/account/push/key', { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (body) {
                if (!body.key) {
                    // No VAPID keys on this deployment. Saying so beats a
                    // button that fails with a console error.
                    button.hidden = true;
                    say('Push is not configured on this server yet.', 'warn');
                    return null;
                }

                return registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: decodeKey(body.key)
                });
            })
            .then(function (subscription) {
                if (!subscription) {
                    return null;
                }

                return post('/account/push/subscribe', subscription.toJSON()).then(showOn);
            })
            .catch(function (error) {
                if (Notification.permission === 'denied') {
                    button.hidden = true;
                    say('You blocked notifications. Allow them in your browser settings to turn this on.', 'warn');
                    return;
                }

                say('Could not turn push on: ' + (error && error.message ? error.message : 'unknown error'), 'warn');
                showOff();
            });
    });
})();
