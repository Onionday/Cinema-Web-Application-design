/* =====================================================================
 * js/seats.js
 *
 * The interactive seat picker on booking.php.
 *
 * Every seat is a real <button> rendered by PHP, already marked as free,
 * taken or held. This script only toggles the selection, keeps the price
 * summary in step and writes the chosen seat ids into a hidden field that
 * the form posts to process_preference.php.
 *
 * Nothing here decides whether a seat is actually free - the server
 * re-checks that, and the UNIQUE key on booking_seats is the final word.
 * Plain DOM only: no library, no AJAX.
 * ===================================================================== */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var map = document.getElementById('seatmap');
        if (!map) { return; }

        var hidden    = document.getElementById('seat_ids');
        var chosenBox = document.getElementById('chosen-seats');
        var countOut  = document.getElementById('sum-count');
        var ticketsOut= document.getElementById('sum-tickets');
        var feeOut    = document.getElementById('sum-fee');
        var totalOut  = document.getElementById('sum-total');
        var hintOut   = document.getElementById('seat-hint');
        var submitBtn = document.getElementById('add-btn');

        var maxSeats = parseInt(map.getAttribute('data-max'), 10) || 8;
        var fee      = parseFloat(map.getAttribute('data-fee')) || 0;
        var currency = map.getAttribute('data-currency') || '$';

        /* ordered list of selected seat buttons */
        var chosen = [];

        function fmt(n) {
            return currency + n.toFixed(2);
        }

        function seatName(btn) {
            return btn.getAttribute('data-row') + btn.getAttribute('data-num');
        }

        /** Re-draws the chips, the totals and the submit button. */
        function paint() {
            /* chips */
            chosenBox.textContent = '';

            if (chosen.length === 0) {
                var none = document.createElement('span');
                none.className = 'faint small';
                none.textContent = 'No seats chosen yet.';
                chosenBox.appendChild(none);
            } else {
                chosen.forEach(function (btn) {
                    var chip = document.createElement('span');
                    chip.className = 'chip';
                    chip.textContent = seatName(btn);
                    chosenBox.appendChild(chip);
                });
            }

            /* money */
            var tickets = 0;
            chosen.forEach(function (btn) {
                tickets += parseFloat(btn.getAttribute('data-price')) || 0;
            });

            var fees  = fee * chosen.length;
            var total = tickets + fees;

            countOut.textContent   = chosen.length +
                                     (chosen.length === 1 ? ' seat' : ' seats');
            ticketsOut.textContent = fmt(tickets);
            feeOut.textContent     = fmt(fees);
            totalOut.textContent   = fmt(total);

            /* hidden field the form actually posts */
            hidden.value = chosen.map(function (btn) {
                return btn.getAttribute('data-seat');
            }).join(',');

            /* button + hint */
            if (chosen.length === 0) {
                submitBtn.classList.add('is-disabled');
                submitBtn.setAttribute('aria-disabled', 'true');
                hintOut.textContent = 'Pick at least one seat to continue.';
            } else {
                submitBtn.classList.remove('is-disabled');
                submitBtn.removeAttribute('aria-disabled');
                hintOut.textContent = chosen.length >= maxSeats
                    ? 'That is the maximum of ' + maxSeats + ' seats per screening.'
                    : 'You may choose up to ' + maxSeats + ' seats.';
            }

            /* grey out the remaining free seats once the cap is reached */
            var free = map.querySelectorAll('.seat:not(:disabled)');
            Array.prototype.forEach.call(free, function (btn) {
                if (btn.classList.contains('is-selected')) { return; }
                if (chosen.length >= maxSeats) {
                    btn.classList.add('is-capped');
                    btn.style.opacity = '0.4';
                } else {
                    btn.classList.remove('is-capped');
                    btn.style.opacity = '';
                }
            });
        }

        function toggle(btn) {
            if (btn.disabled) { return; }

            var i = chosen.indexOf(btn);

            if (i >= 0) {
                chosen.splice(i, 1);
                btn.classList.remove('is-selected');
                btn.setAttribute('aria-pressed', 'false');
            } else {
                if (chosen.length >= maxSeats) {
                    hintOut.textContent =
                        'You can book at most ' + maxSeats +
                        ' seats for one screening. Deselect one first.';
                    return;
                }
                chosen.push(btn);
                btn.classList.add('is-selected');
                btn.setAttribute('aria-pressed', 'true');
            }

            paint();
        }

        /* One listener on the map rather than one per seat. */
        map.addEventListener('click', function (ev) {
            var btn = ev.target.closest('.seat');
            if (btn && map.contains(btn)) {
                ev.preventDefault();
                toggle(btn);
            }
        });

        /* Left / right / up / down move between seats for keyboard users. */
        map.addEventListener('keydown', function (ev) {
            var btn = ev.target.closest('.seat');
            if (!btn) { return; }

            var keys = { ArrowLeft: -1, ArrowRight: 1 };
            if (ev.key in keys) {
                ev.preventDefault();
                var all = Array.prototype.slice.call(map.querySelectorAll('.seat'));
                var idx = all.indexOf(btn) + keys[ev.key];
                while (idx >= 0 && idx < all.length && all[idx].disabled) {
                    idx += keys[ev.key];
                }
                if (all[idx]) { all[idx].focus(); }
            }
        });

        /* Clear-all link. */
        var clearBtn = document.getElementById('clear-seats');
        if (clearBtn) {
            clearBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                chosen.slice().forEach(function (b) { toggle(b); });
            });
        }

        /* Refuse to post an empty selection. */
        var form = document.getElementById('seat-form');
        if (form) {
            form.addEventListener('submit', function (ev) {
                if (chosen.length === 0) {
                    ev.preventDefault();
                    hintOut.textContent = 'Please choose at least one seat first.';
                    map.scrollIntoView({ block: 'center' });
                }
            });
        }

        /* Seats the visitor had chosen before a failed submit come back
           marked data-preselect, so restore them. */
        Array.prototype.forEach.call(
            map.querySelectorAll('.seat[data-preselect="1"]'),
            function (btn) { toggle(btn); }
        );

        paint();
    });

}());
