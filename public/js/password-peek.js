/*
 * Let someone see the password they are typing.
 *
 * A password field is the one input on the site that gives no feedback at all:
 * on a phone keyboard, with a long password, the only way to find a typo is to
 * clear the box and start again. The usual result is a weaker password chosen
 * because it is easier to type, which is the opposite of what hiding it was
 * for. Showing it is the person's call — they are the one who can see whether
 * anybody is looking over their shoulder.
 *
 * Done to every password input on the page rather than through a component,
 * because they are not all built the same way: some come from <x-field> and
 * some are written out by hand, and two of them share the name "password"
 * while needing different ids. Matching on the type catches all of them, and
 * catches the next one somebody adds without having to remember anything.
 *
 * Nothing here is required to sign in. With no JavaScript the field is exactly
 * what it was.
 */
(function () {
    'use strict';

    var fields = document.querySelectorAll('input[type="password"]');

    if (!fields.length) {
        return;
    }

    /* Two eyes, swapped by CSS on the button's state, the same way the theme
       toggle swaps its sun and moon. */
    var EYES =
        '<svg class="peek-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
        '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z" stroke-linecap="round" stroke-linejoin="round"/>' +
        '<circle cx="12" cy="12" r="3"/></svg>' +
        '<svg class="peek-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
        '<path d="M2 12s3.6-7 10-7c2 0 3.7.7 5.1 1.6M22 12s-3.6 7-10 7c-2 0-3.7-.7-5.1-1.6" stroke-linecap="round" stroke-linejoin="round"/>' +
        '<path d="M4 20 20 4" stroke-linecap="round"/></svg>';

    Array.prototype.forEach.call(fields, function (field) {
        var wrap = document.createElement('span');
        var button = document.createElement('button');

        wrap.className = 'peekwrap';
        field.parentNode.insertBefore(wrap, field);
        wrap.appendChild(field);

        // type="button": inside a form, a button without one submits it, and a
        // login form that signs you in when you meant to look at your password
        // is worse than no button at all.
        button.type = 'button';
        button.className = 'peek';
        button.innerHTML = EYES;
        label(button, false);

        wrap.appendChild(button);

        button.addEventListener('click', function () {
            var shown = field.type === 'text';

            /*
             * Changing the type moves the caret to the end in most browsers
             * and can drop focus entirely, which on a phone closes the
             * keyboard mid-password. Put both back.
             */
            var start = field.selectionStart;
            var end = field.selectionEnd;

            field.type = shown ? 'password' : 'text';
            label(button, !shown);

            field.focus();

            try {
                field.setSelectionRange(start, end);
            } catch (e) {
                /* Some browsers refuse a selection on a password input. The
                   value is intact either way; only the caret is at risk. */
            }
        });
    });

    /*
     * aria-pressed rather than changing the icon alone: a screen reader
     * announces the state, which is the whole point of a control whose effect
     * is visual. The label says what the button will do next.
     */
    function label(button, shown) {
        button.setAttribute('aria-pressed', shown ? 'true' : 'false');
        button.setAttribute('aria-label', shown ? 'Hide password' : 'Show password');
        button.setAttribute('title', shown ? 'Hide password' : 'Show password');
    }
})();
