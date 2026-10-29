/* Additional version: progressively enhanced catalogue search, no framework. */
(function () {
    'use strict';
    const form = document.getElementById('movie-filters');
    if (!form || !window.fetch || !window.AbortController) { return; }
    const results = document.getElementById('movie-results');
    const summary = document.getElementById('catalogue-summary');
    const status = document.getElementById('search-status');
    const retry = document.getElementById('search-retry');
    const input = form.elements.q;
    const defaults = { q: '', status: 'now_showing', genre: '', rating: '', sort: 'score' };
    let timer, controller, version = 0, composing = false;

    function invalidate() {
        clearTimeout(timer);
        if (controller) { controller.abort(); }
        version++;
    }

    async function search(updateHistory = true) {
        invalidate();
        const requestVersion = version;
        const currentController = new AbortController();
        controller = currentController;
        const params = new URLSearchParams(new FormData(form));
        results.setAttribute('aria-busy', 'true');
        status.textContent = 'Searching films…';
        retry.hidden = true;
        let timedOut = false;
        const timeout = setTimeout(function () {
            timedOut = true;
            currentController.abort();
        }, 10000);
        try {
            const response = await fetch('movies_search.php?' + params, {
                signal: currentController.signal,
                headers: { Accept: 'application/json' },
                cache: 'no-store'
            });
            if (!response.ok) { throw new Error('Search unavailable'); }
            const data = await response.json();
            if (typeof data.html !== 'string' || typeof data.summary !== 'string'
                || !Number.isInteger(data.count)) { throw new Error('Invalid response'); }
            if (requestVersion !== version) { return; }
            // Trusted same-origin template output; all database/input text is escaped in PHP.
            results.innerHTML = data.html;
            summary.textContent = data.summary;
            status.textContent = data.count === 0 ? 'No films match your search. Try another title or reset the filters.'
                : data.count + (data.count === 1 ? ' film found.' : ' films found.');
            if (updateHistory) {
                const url = new URL(form.action);
                url.search = params.toString();
                if (url.href !== location.href) { history.pushState(null, '', url); }
            }
        } catch (error) {
            if (requestVersion !== version) { return; }
            status.textContent = timedOut
                ? 'Search took too long. Previous results are still shown. Please retry.'
                : 'Could not update films. Previous results are still shown. Check your connection and retry.';
            retry.hidden = false;
        } finally {
            clearTimeout(timeout);
            if (requestVersion === version) { results.setAttribute('aria-busy', 'false'); }
        }
    }

    function schedule() {
        // Invalidate immediately, not after the debounce: old responses cannot replace new input.
        invalidate();
        results.setAttribute('aria-busy', 'true');
        status.textContent = 'Waiting for you to finish typing…';
        retry.hidden = true;
        if (!composing) { timer = setTimeout(search, 300); }
    }
    input.addEventListener('compositionstart', function () { composing = true; invalidate(); });
    input.addEventListener('compositionend', function () { composing = false; schedule(); });
    input.addEventListener('input', schedule);
    form.querySelectorAll('select').forEach(function (select) {
        select.addEventListener('change', function () { search(); });
    });
    form.addEventListener('submit', function (event) { event.preventDefault(); search(); });
    retry.addEventListener('click', function () { search(); });
    document.addEventListener('click', function (event) {
        const reset = event.target.closest('[data-search-reset]');
        if (!reset || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) { return; }
        event.preventDefault();
        Object.keys(defaults).forEach(function (key) { form.elements[key].value = defaults[key]; });
        input.focus();
        search();
    });
    window.addEventListener('popstate', function () {
        const params = new URLSearchParams(location.search);
        Object.keys(defaults).forEach(function (key) {
            form.elements[key].value = params.get(key) ?? defaults[key];
        });
        search(false);
    });
}());
