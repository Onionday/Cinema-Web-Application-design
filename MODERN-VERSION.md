# CineSphere additional version: live catalogue search

This checkout is the `modern-live-search` branch based on base commit `f0e56f7` (including the latest wording changes).
The original base is preserved on `base-version-before-live-search` at `f0e56f7`; the combined version is published to `main`. Fetch/AJAX and JSON belong only to this
additional version, since the coursework prohibits them in the base version.

## What changed

- Typing searches after a 300 ms pause. Dropdown filters and Apply search immediately.
- Only the results and summary change; the search field keeps focus.
- Loading, empty, connection failure and retry messages are announced through a
  polite live region. Failed requests retain the previous results and label them as such.
- Requests time out after 10 seconds. Superseded requests are aborted, and a
  generation check prevents late responses overwriting a newer search.
- Reset, shareable URLs, and browser Back/Forward work with the current filters.
- Ordinary PHP form submission remains available without JavaScript or Fetch support.

The existing PHP query and escaped card markup were extracted into
`cinesphere/includes/catalogue.php` and `catalogue_results.php`. Both the full
HTML page and `movies_search.php` use these files, so search rules and display
markup have one source. The read-only GET endpoint returns JSON containing
server-rendered HTML, a count and a summary. It accepts only scalar, bounded
filter values, retains prepared SQL parameters and allowlisted sort expressions,
and releases the session lock before querying. No schema migration is needed.

`js/movies.js` supplies the enhancement. React and WebSockets are not used.
This feature does not add live seat availability or customer seat changes.

## Local comparison

- Base: http://127.0.0.1:8087/cinesphere/movies.php
- Additional version: http://127.0.0.1:8088/cinesphere/movies.php

The local previews use the same existing database and mailbox. They are separate
code checkouts, not isolated customer accounts: cookies on the same hostname
can be shared across ports. Use separate browser profiles for two customers.

With the local MariaDB and mail services running, serve this checkout using
PHP's development server (`-c` points to the existing local php.ini):

```powershell
& '..\CineSphere-local\php\php.exe' -c '..\CineSphere-local\php.ini' -S 127.0.0.1:8088 -t .
```

## Demonstration and justification

Type `Meridian`, then `12345`, change the filters, press Reset and use Back.
Results update without a document navigation. The benefit is maintaining the
customer's place and keyboard focus while comparing films; the debounce avoids
one request for every keystroke. Prepared queries and escaped templates remain
server-side. Demonstrate the ordinary base search at port 8087 for comparison.

## Verification

`tests/search_tests.js` runs nine browser/API scenarios against a running local
site. Install/resolve Playwright in Node and use `PLAYWRIGHT_CHANNEL=msedge` if
using installed Edge:

```powershell
node tests/search_tests.js http://127.0.0.1:8088/cinesphere/
```

See `tests/search-test-results.md` for the latest run. The test uses read-only
searches and does not reset the existing database. Screenshots are written to
`tests/search-mobile.png` and `tests/search-desktop.png` for visual review.
The earlier 51 server and six browser results describe the base version;
they were not rerun for this catalogue-only enhancement.
