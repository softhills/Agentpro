/*
 * Thousands separators in the money fields (FR-M7-05).
 *
 * Figures here run to eight digits. "4500000" and "450000" are one keystroke
 * and one order of magnitude apart, and nobody reads that difference reliably
 * off an unbroken run of zeros — which on a listing form means a rent
 * advertised at ten times the asking price, and on a payout form means a
 * withdrawal request nobody meant to make.
 *
 * Server-rendered values arrive already grouped, from Money::field(). This
 * keeps them grouped while they are being typed, and does nothing else: the
 * commas go to the server exactly as they appear, and MoneyInput::clean()
 * takes them back out before validation. Stripping them here on submit as
 * well would hide a missing clean() call from everyone who has JavaScript —
 * that is, from everyone who tests it.
 */
(function () {
    'use strict';

    var fields = document.querySelectorAll('input[data-money]');

    if (!fields.length) {
        return;
    }

    /* Digits and at most one decimal point, and at most two places after it —
       kobo exist, but a third place is a typo in every form that has one of
       these fields. Everything else a user can type is dropped, which is also
       how a pasted "₦4,500,000" becomes a usable value rather than an error. */
    function clean(raw) {
        var kept = raw.replace(/[^\d.]/g, '');
        var dot = kept.indexOf('.');

        if (dot === -1) {
            return kept;
        }

        return kept.slice(0, dot + 1) + kept.slice(dot + 1).replace(/\./g, '').slice(0, 2);
    }

    function group(digits) {
        return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function format(raw) {
        var parts = clean(raw).split('.');

        return parts.length > 1 ? group(parts[0]) + '.' + parts[1] : group(parts[0]);
    }

    /*
     * Where the caret belongs after re-grouping.
     *
     * Counted in characters the user actually typed rather than in string
     * positions, because inserting or removing a comma shifts every position
     * after it. Without this, typing into the middle of a long price throws
     * the caret to the wrong side of a separator on every third digit, and the
     * number comes out scrambled.
     */
    function caretAfter(formatted, typed) {
        var seen = 0;

        if (typed <= 0) {
            return 0;
        }

        for (var i = 0; i < formatted.length; i++) {
            if (formatted.charAt(i) !== ',') {
                seen++;

                if (seen === typed) {
                    return i + 1;
                }
            }
        }

        return formatted.length;
    }

    function typedBefore(value, caret) {
        return value.slice(0, caret).replace(/[^\d.]/g, '').length;
    }

    /* Tidying is left until the field is left alone. Doing it on every
       keystroke would delete the "." the moment it is typed and strip the
       leading zero of "0.5" before the rest of it exists. */
    function tidy(value) {
        var cleaned = clean(value);
        var parts;

        if (cleaned === '' || cleaned === '.') {
            return '';
        }

        parts = cleaned.replace(/\.$/, '').split('.');
        parts[0] = parts[0].replace(/^0+(?=\d)/, '') || '0';

        return parts.length > 1 ? group(parts[0]) + '.' + parts[1] : group(parts[0]);
    }

    Array.prototype.forEach.call(fields, function (field) {
        field.addEventListener('input', function () {
            var typed = typedBefore(field.value, field.selectionStart);
            var formatted = format(field.value);
            var caret;

            if (formatted === field.value) {
                return;
            }

            field.value = formatted;
            caret = caretAfter(formatted, typed);

            /* Throws on an input the browser considers unselectable; the value
               is already correct by this point, only the caret is at risk. */
            try {
                field.setSelectionRange(caret, caret);
            } catch (e) {}
        });

        field.addEventListener('blur', function () {
            field.value = tidy(field.value);
        });
    });
})();
