/* =====================================================================
 * js/validate.js
 *
 * Client-side validation for every form on the site.
 *
 * This runs IN ADDITION to the HTML5 attributes on the inputs and to the
 * server-side checks in includes/functions.php. Nothing here is trusted:
 * it exists so the visitor gets an answer the instant they leave a field
 * instead of after a round trip. The rules are deliberately identical to
 * the PHP ones so the two never disagree.
 *
 * Plain DOM only - no library, no AJAX.
 * ===================================================================== */

(function () {
    'use strict';

    /* ---------------------------------------------------------------- */
    /*  Individual rules. Each returns '' when valid, or a message.      */
    /* ---------------------------------------------------------------- */

    var RULES = {

        required: function (value, field) {
            return value.trim() === '' ? (label(field) + ' is required.') : '';
        },

        name: function (value, field) {
            var v = value.trim().replace(/\s+/g, ' ');
            if (v === '') { return label(field) + ' is required.'; }
            if (v.length < 3 || v.length > 80) {
                return label(field) + ' must be between 3 and 80 characters.';
            }
            if (!/^[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'.\- ]*$/.test(v)) {
                return 'Use letters, spaces, apostrophes, full stops and hyphens only.';
            }
            return '';
        },

        email: function (value) {
            var v = value.trim();
            if (v === '') { return 'Email address is required.'; }
            if (v.length > 120) { return 'Email address is too long.'; }
            if (!/^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/.test(v)) {
                return 'That does not look like a valid email address.';
            }
            return '';
        },

        phone: function (value) {
            var v = value.trim();
            var digits = v.replace(/\D/g, '');
            if (v === '') { return 'Contact number is required.'; }
            if (!/^\+?[0-9 \-()]{8,20}$/.test(v) || digits.length < 8) {
                return 'Enter a valid contact number (at least 8 digits).';
            }
            return '';
        },

        password: function (value) {
            if (value === '') { return 'Password is required.'; }
            if (value.length < 8 || value.length > 64) {
                return 'Password must be 8 to 64 characters long.';
            }
            if (!/[A-Za-z]/.test(value)) { return 'Add at least one letter.'; }
            if (!/[0-9]/.test(value))    { return 'Add at least one number.'; }
            if (!/[^A-Za-z0-9]/.test(value)) { return 'Add at least one symbol, e.g. ! ? @ #'; }
            return '';
        },

        /* data-match="#password" */
        match: function (value, field) {
            var other = document.querySelector(field.getAttribute('data-match'));
            if (!other) { return ''; }
            if (value === '') { return 'Please re-type the password.'; }
            return value === other.value ? '' : 'The two passwords do not match.';
        },

        /* Account holders must be 13 or older. */
        birthdate: function (value) {
            if (value.trim() === '') { return ''; }          // optional
            var parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
            if (!parts) { return 'Enter the date as YYYY-MM-DD.'; }

            var d = new Date(+parts[1], +parts[2] - 1, +parts[3]);
            if (d.getFullYear() !== +parts[1] ||
                d.getMonth() !== +parts[2] - 1 ||
                d.getDate() !== +parts[3]) {
                return 'That date does not exist.';
            }

            var today = new Date();
            var age = today.getFullYear() - d.getFullYear();
            var m = today.getMonth() - d.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < d.getDate())) { age--; }

            if (age < 13)  { return 'Account holders must be at least 13 years old.'; }
            if (age > 120) { return 'Please check the year of birth.'; }
            return '';
        },

        /* data-minlen="20" */
        minlen: function (value, field) {
            var n = parseInt(field.getAttribute('data-minlen'), 10) || 0;
            var v = value.trim();
            if (v.length < n) {
                return label(field) + ' needs at least ' + n + ' characters (' +
                       v.length + ' so far).';
            }
            return '';
        },

        /* data-maxlen="1200" */
        maxlen: function (value, field) {
            var n = parseInt(field.getAttribute('data-maxlen'), 10) || 0;
            if (value.trim().length > n) {
                return label(field) + ' must be ' + n + ' characters or fewer.';
            }
            return '';
        },

        /* A <select> whose first option is a placeholder with value "". */
        choose: function (value, field) {
            return value === '' ? ('Please choose ' + label(field).toLowerCase() + '.') : '';
        },

        /* A checkbox that has to be ticked. */
        accept: function (value, field) {
            return field.checked ? '' : 'Please tick this box to continue.';
        },

        /* data-min / data-max on a number input. */
        number: function (value, field) {
            if (value.trim() === '') { return label(field) + ' is required.'; }
            var n = Number(value);
            if (isNaN(n)) { return 'Enter a number.'; }
            var min = field.getAttribute('data-min');
            var max = field.getAttribute('data-max');
            if (min !== null && n < Number(min)) { return 'Minimum is ' + min + '.'; }
            if (max !== null && n > Number(max)) { return 'Maximum is ' + max + '.'; }
            return '';
        },

        /* A date that must not be in the past. */
        futuredate: function (value, field) {
            if (value.trim() === '') { return label(field) + ' is required.'; }
            var today = new Date();
            today.setHours(0, 0, 0, 0);
            var d = new Date(value + 'T00:00:00');
            if (isNaN(d.getTime())) { return 'Enter the date as YYYY-MM-DD.'; }
            return d < today ? 'Please choose today or a later date.' : '';
        }
    };


    /* ---------------------------------------------------------------- */
    /*  Helpers                                                          */
    /* ---------------------------------------------------------------- */

    function label(field) {
        var wrap = field.closest('.field');
        var lab = wrap ? wrap.querySelector('label') : null;
        if (!lab) { return 'This field'; }
        return lab.textContent.replace('*', '').replace(/\s+/g, ' ').trim();
    }

    function errorBox(field) {
        var wrap = field.closest('.field') || field.parentNode;
        var box = wrap.querySelector('.error');
        if (!box) {
            box = document.createElement('span');
            box.className = 'error';
            box.setAttribute('role', 'alert');
            wrap.appendChild(box);
        }
        return box;
    }

    /** Runs every rule named in data-check on one field. */
    function checkField(field) {
        var spec = field.getAttribute('data-check');
        if (!spec) { return true; }

        var message = '';
        var names = spec.split(/\s+/);

        for (var i = 0; i < names.length; i++) {
            var rule = RULES[names[i]];
            if (typeof rule === 'function') {
                message = rule(field.value, field);
                if (message) { break; }
            }
        }

        var wrap = field.closest('.field');
        errorBox(field).textContent = message;

        if (wrap) {
            if (message) {
                wrap.classList.add('has-error');
            } else {
                wrap.classList.remove('has-error');
            }
        }

        field.setAttribute('aria-invalid', message ? 'true' : 'false');
        return message === '';
    }

    /** Validates a whole form; focuses the first bad field. */
    function checkForm(form) {
        var fields = form.querySelectorAll('[data-check]');
        var ok = true;
        var first = null;

        for (var i = 0; i < fields.length; i++) {
            if (!checkField(fields[i])) {
                ok = false;
                if (!first) { first = fields[i]; }
            }
        }

        if (!ok && first) {
            first.focus();
            var summary = form.querySelector('.form-summary');
            if (summary) {
                summary.textContent = 'Please correct the highlighted fields before continuing.';
                summary.hidden = false;
            }
        }

        return ok;
    }


    /* ---------------------------------------------------------------- */
    /*  Password strength meter                                          */
    /* ---------------------------------------------------------------- */

    function wireMeter(input) {
        var meter = document.querySelector(input.getAttribute('data-meter'));
        if (!meter) { return; }

        var bar = meter.querySelector('i');

        input.addEventListener('input', function () {
            var v = input.value;
            var score = 0;

            if (v.length >= 8)  { score++; }
            if (v.length >= 12) { score++; }
            if (/[a-z]/.test(v) && /[A-Z]/.test(v)) { score++; }
            if (/[0-9]/.test(v)) { score++; }
            if (/[^A-Za-z0-9]/.test(v)) { score++; }

            bar.style.width = (score / 5 * 100) + '%';
            meter.classList.remove('ok', 'good');
            if (score >= 4)      { meter.classList.add('good'); }
            else if (score >= 3) { meter.classList.add('ok'); }
        });
    }


    /* ---------------------------------------------------------------- */
    /*  Live character counter for textareas                             */
    /* ---------------------------------------------------------------- */

    function wireCounter(field) {
        var out = document.querySelector(field.getAttribute('data-counter'));
        if (!out) { return; }

        var max = parseInt(field.getAttribute('data-maxlen'), 10) || 0;

        function paint() {
            var n = field.value.trim().length;
            out.textContent = max ? (n + ' / ' + max + ' characters')
                                  : (n + ' characters');
        }

        field.addEventListener('input', paint);
        paint();
    }


    /* ---------------------------------------------------------------- */
    /*  Wiring                                                           */
    /* ---------------------------------------------------------------- */

    document.addEventListener('DOMContentLoaded', function () {

        var forms = document.querySelectorAll('form[data-validate]');

        Array.prototype.forEach.call(forms, function (form) {

            form.setAttribute('novalidate', 'novalidate');   // we do it ourselves

            var fields = form.querySelectorAll('[data-check]');

            Array.prototype.forEach.call(fields, function (field) {

                /* Check when the visitor leaves the field ... */
                field.addEventListener('blur', function () {
                    checkField(field);
                });

                /* ... and again as they type, but only once it has already
                   been marked wrong, so nobody is scolded mid-word. */
                field.addEventListener('input', function () {
                    var wrap = field.closest('.field');
                    if (wrap && wrap.classList.contains('has-error')) {
                        checkField(field);
                    }
                });

                if (field.type === 'checkbox') {
                    field.addEventListener('change', function () { checkField(field); });
                }

                if (field.hasAttribute('data-meter'))   { wireMeter(field); }
                if (field.hasAttribute('data-counter')) { wireCounter(field); }
            });

            /* Re-check the confirm field whenever the password changes. */
            var confirmField = form.querySelector('[data-match]');
            if (confirmField) {
                var src = document.querySelector(confirmField.getAttribute('data-match'));
                if (src) {
                    src.addEventListener('input', function () {
                        if (confirmField.value !== '') { checkField(confirmField); }
                    });
                }
            }

            var submitting = false;

            form.addEventListener('submit', function (ev) {
                if (!checkForm(form)) {
                    ev.preventDefault();
                    return;
                }

                /* Stop an impatient double-click producing two bookings.
                   The button is NOT disabled here - a disabled submit
                   button is left out of the POST data by some browsers -
                   so a flag guards the second submit instead. */
                if (submitting) {
                    ev.preventDefault();
                    return;
                }

                submitting = true;
                form.classList.add('is-submitting');

                var btn = form.querySelector('button[type="submit"]');
                if (btn && btn.getAttribute('data-busy')) {
                    btn.textContent = btn.getAttribute('data-busy');
                }

                /* If the page is still here after a few seconds the submit
                   failed somewhere; let the visitor try again. */
                window.setTimeout(function () {
                    submitting = false;
                    form.classList.remove('is-submitting');
                }, 8000);
            });
        });
    });

}());
