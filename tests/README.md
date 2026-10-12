# Base-version regression evidence

Run: `python tests/base_regression.py` from the repository root.

Requires the sibling `CineSphere-local` portable runtime with MariaDB and Mailpit running. Tests use their own database and PHP ports 18087/18088. The SMTP inbox receives clearly labelled test messages.

| Scenario | Method / evidence | Result |
|---|---|---|
| Customer and staff sign-in | HTTP request with database/inbox assertions | PASS |
| No-JavaScript checkbox submission adds a short-list choice | HTTP request with database/inbox assertions | PASS |
| Declined payment keeps preference and allocates no seat | HTTP request with database/inbox assertions | PASS |
| Approved payment creates ticket and history snapshot | HTTP request with database/inbox assertions | PASS |
| Acknowledgement accepted by local SMTP inbox | HTTP request with database/inbox assertions | PASS |
| All delivered messages target only the local coursework mailbox | HTTP request with database/inbox assertions | PASS |
| Cancellation refunds, releases seat and preserves original seat history | HTTP request with database/inbox assertions | PASS |
| Cancelled history still shows A1 | HTTP request with database/inbox assertions | PASS |
| Cancelled reference cannot render a valid confirmation ticket | HTTP request with database/inbox assertions | PASS |
| Stale second customer cannot buy the same seat | HTTP request with database/inbox assertions | PASS |
| Customer cannot access staff page | HTTP request with database/inbox assertions | PASS |
| Cross-midnight overlap rejected | HTTP request with database/inbox assertions | PASS |
| Turnaround overlap rejected | HTTP request with database/inbox assertions | PASS |
| Exact turnaround boundary accepted | HTTP request with database/inbox assertions | PASS |
| Reverse cross-midnight overlap rejected | HTTP request with database/inbox assertions | PASS |
| Replacement after cancellation accepted | HTTP request with database/inbox assertions | PASS |
| Reopening a conflicting cancelled screening rejected | HTTP request with database/inbox assertions | PASS |
| Staff cancellation rolls back if any write fails | HTTP request with database/inbox assertions | PASS |
| Staff cancellation releases seats, keeps history and sends notice | HTTP request with database/inbox assertions | PASS |
| Mail failure leaves confirmed ticket but reports delivery failure | HTTP request with database/inbox assertions | PASS |

Manual browser checks:

- Seat A1 + ArrowDown focuses B1; ArrowRight moves to the adjacent available seat.
- Space selects A1, total becomes S$18.30 (S$17.50 ticket + S$0.80 fee), and the live region announces it.
- One seat is in the normal Tab order; every seat has a complete accessible name.
- At 390px, Enter opens the menu; Escape closes it and returns focus to the menu button.
- At a 390px viewport, document scroll width is 375px (scrollbar excluded); seat map width is 337px with 714px of scrollable content. No page-wide overflow.

The report is test evidence, not a claim that every application requirement or concurrent workload has been exhaustively verified. The course report still needs your requirements, storyboard, wireframes and implementation discussion.
