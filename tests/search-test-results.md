# Modern catalogue search tests

Run: 2026-10-29T13:49:07.238Z. Playwright / Edge against http://127.0.0.1:8088/cinesphere/

9/9 passed. Read-only searches; existing local database not reset.

| Scenario | Result |
|---|---|
| Typing updates cards without navigation and preserves focus | PASS |
| Unknown keyword shows empty state and reset restores catalogue | PASS |
| Combined keyword/status/genre/rating/sort matches server fallback | PASS |
| An outdated delayed response cannot overwrite a newer search | PASS |
| Network failure retains results; retry recovers | PASS |
| Back and Forward restore controls and results without reloading | PASS |
| HTML-like search is escaped; malformed parameters are handled | PASS |
| Mobile search works with no page overflow or JavaScript errors | PASS |
| Without JavaScript, Apply still performs ordinary PHP search | PASS |
