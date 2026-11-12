









(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {




        var toggle = document.getElementById('nav-toggle');
        var navigation = document.getElementById('site-navigation');
        if (toggle && navigation) {
            var mobile = window.matchMedia('(max-width: 860px)');
            function setMenu(open) {
                navigation.classList.toggle('is-open', open);
                toggle.setAttribute('aria-expanded', String(open));
                toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
            }
            toggle.addEventListener('click', function () {
                setMenu(toggle.getAttribute('aria-expanded') !== 'true');
            });
            navigation.querySelectorAll('a').forEach(function (a) {
                a.addEventListener('click', function () { if (mobile.matches) { setMenu(false); } });
            });
            document.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape' && mobile.matches && navigation.classList.contains('is-open')) {
                    setMenu(false);
                    toggle.focus();
                }
            });
            function resizeMenu() { setMenu(!mobile.matches); }
            mobile.addEventListener('change', resizeMenu);
            resizeMenu();
        }








        var confirms = document.querySelectorAll('[data-confirm]');
        Array.prototype.forEach.call(confirms, function (btn) {

            var original = btn.textContent;
            var armed = false;
            var timer = null;

            btn.addEventListener('click', function (ev) {
                if (armed) { return; }

                ev.preventDefault();
                armed = true;
                btn.textContent = btn.getAttribute('data-confirm');
                btn.classList.add('btn-danger');

                timer = window.setTimeout(function () {
                    armed = false;
                    btn.textContent = original;
                    btn.classList.remove('btn-danger');
                }, 5000);
            });

            btn.addEventListener('blur', function () {
                if (!armed) { return; }
                window.clearTimeout(timer);
                armed = false;
                btn.textContent = original;
                btn.classList.remove('btn-danger');
            });
        });







        var autoForms = document.querySelectorAll('form[data-autosubmit]');
        Array.prototype.forEach.call(autoForms, function (form) {
            var controls = form.querySelectorAll('select, input[type="date"]');
            Array.prototype.forEach.call(controls, function (c) {
                c.addEventListener('change', function () { form.submit(); });
            });
        });






        var prefForm = document.getElementById('confirm-form');
        if (prefForm) {

            var boxes    = prefForm.querySelectorAll('.pref-pick');
            var totalOut = document.getElementById('pref-total');
            var countOut = document.getElementById('pref-count');
            var payBtn   = document.getElementById('pay-btn');
            var currency = prefForm.getAttribute('data-currency') || '$';

            var paint = function () {
                var total = 0;
                var seats = 0;
                var picked = 0;

                Array.prototype.forEach.call(boxes, function (b) {
                    var row = b.closest('.pref-item');
                    if (b.checked) {
                        picked++;
                        total += parseFloat(b.getAttribute('data-amount')) || 0;
                        seats += parseInt(b.getAttribute('data-seats'), 10) || 0;
                        if (row) { row.classList.add('is-picked'); }
                    } else if (row) {
                        row.classList.remove('is-picked');
                    }
                });

                if (totalOut) { totalOut.textContent = currency + total.toFixed(2); }

                if (countOut) {
                    countOut.textContent = picked === 0
                        ? 'Nothing selected'
                        : picked + (picked === 1 ? ' booking' : ' bookings') +
                          ', ' + seats + (seats === 1 ? ' seat' : ' seats');
                }

                if (payBtn) {
                    if (picked === 0) {
                        payBtn.classList.add('is-disabled');
                        payBtn.setAttribute('aria-disabled', 'true');
                    } else {
                        payBtn.classList.remove('is-disabled');
                        payBtn.removeAttribute('aria-disabled');
                    }
                }
            };

            Array.prototype.forEach.call(boxes, function (b) {
                b.addEventListener('change', paint);
            });

            prefForm.addEventListener('submit', function (ev) {
                var any = false;
                Array.prototype.forEach.call(boxes, function (b) {
                    if (b.checked) { any = true; }
                });
                if (!any) {
                    ev.preventDefault();
                    var warn = document.getElementById('pref-warning');
                    if (warn) {
                        warn.textContent =
                            'Tick at least one available booking before confirming.';
                        warn.hidden = false;
                    }
                }
            });

            paint();
        }





        var allBtn = document.getElementById('pick-all');
        if (allBtn) {
            allBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                var boxes = document.querySelectorAll('.pref-pick:not(:disabled)');
                var every = true;
                Array.prototype.forEach.call(boxes, function (b) {
                    if (!b.checked) { every = false; }
                });
                Array.prototype.forEach.call(boxes, function (b) {
                    b.checked = !every;
                    b.dispatchEvent(new Event('change'));
                });
                allBtn.textContent = every ? 'Select all available'
                                           : 'Clear selection';
            });
        }





        var printBtn = document.getElementById('print-ticket');
        if (printBtn) {
            printBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                window.print();
            });
        }






        var barcodes = document.querySelectorAll('.barcode[data-ref]');
        Array.prototype.forEach.call(barcodes, function (bc) {
            var ref = bc.getAttribute('data-ref');
            var seed = 0;
            var i;

            for (i = 0; i < ref.length; i++) {
                seed = (seed * 31 + ref.charCodeAt(i)) % 100000;
            }

            for (i = 0; i < 34; i++) {
                seed = (seed * 1103515245 + 12345) % 2147483648;
                var bar = document.createElement('i');
                bar.style.height = (34 + (seed % 66)) + '%';
                bar.style.width = ((seed % 3) + 1) + 'px';
                bc.appendChild(bar);
            }
        });

    });

}());
