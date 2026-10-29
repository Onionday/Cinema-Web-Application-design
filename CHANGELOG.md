# Changes to the CineSphere base version

## 29 October 2026 — additional version only

Branch `modern-live-search` adds debounced catalogue search and immediate filter
updates without page reloads. Shared PHP query and escaped result templates serve
both the JSON endpoint and ordinary form fallback. Includes loading/empty/error
feedback, retry, timeout, stale-response protection, URL history and reset.
Nine browser/API scenarios passed in headless Edge; desktop and mobile screenshots
were visually reviewed. No database migration. The base checkout remains unchanged.
See `MODERN-VERSION.md` and `tests/search-test-results.md`.

## 28 October 2026

Fixed three checkout/refund issues found while reviewing main `bcc1e9a`:

- Checkout now remembers the seats and prices displayed using a session-backed,
  single-use quote. Quotes last 15 minutes and support multiple tabs. Missing,
  expired or changed quotes require the customer to review and confirm again.
  Checkout re-reads the choice and prices after acquiring locks; shortlist
  creation also prices under the screening lock.
- Moving to a more expensive seat requires an approved simulated supplementary
  payment. Missing or declined approval changes nothing. Same-price moves need
  no extra payment; cheaper seats refund the difference. The adjustment log and
  email record approved payment, instead of promising collection later.
- Staff and customer cancellations read current monetary state and held seats
  after acquiring locks. Staff notifications use the exact operation snapshot.
  Refunding all seats validates the complete selection inside the same transaction.

No database schema change is required. This does not invent settlement records
for unpaid seat upgrades made before this fix; those older adjustments need to
be checked against any actual box-office settlement records before refunding.

Validation: 51/51 server tests and 6/6 browser tests passed. Nine new cases cover
stale/forged quotes, in-flight repricing, upgrade approval/decline, downgrade and
refund-all, and concurrent partial refunds against staff/customer cancellation.
The refund races use real PHP functions in separate processes and a database
lock barrier. The in-flight price test controls the timing with equivalent SQL
repricing writes; normal staff repricing is covered through HTTP elsewhere.
PHP/JavaScript syntax checks also passed. Tests use disposable databases and a
local mail catcher. See `tests/README.md` for commands and result files.

## 26 October 2026

New staff features, a self-maintaining schedule, an automated test suite, and
two bug fixes found by those tests. Still Base Version technology only (HTML,
CSS, JavaScript, PHP, MySQL); the page count is unchanged at home + 10.

### Rolling schedule

| Change | Why |
|---|---|
| `includes/rolling_schedule.php`: on each page load, any of the next 7 days with no screenings is filled by copying the same weekday from the latest week that has one | The seed dates screenings relative to the import day, so a week after importing the schedule went empty and nothing could be booked |
| Copies skip cancelled screenings, films not "now showing", and anything that would clash in a hall; a day staff have scheduled is left as planned; a database lock prevents double filling | Keeps the generated schedule as valid as one entered by staff |

### Staff booking tools and ticket prices (`admin.php`)

| Change | Why |
|---|---|
| **Manage a booking**: find by reference (or *Manage* under Recent bookings) | Staff needed to change a booking on a customer's behalf |
| Refund selected seats (e.g. bought 3, refund 1): seat price + S$0.80 fee returned, seat back on sale; refunding all seats cancels the booking | Partial refunds were not possible before |
| Move a seat to any free seat at the same screening; customer keeps the price paid and only the seat-type difference is added or refunded | Seat changes without cancelling and rebooking |
| Cancel and refund a whole booking, with no 2-hour cut-off for staff | Box-office cancellations |
| Every change needs a reason, emails the customer and is logged in the new `booking_adjustments` table (who, when, why, amount) | Accountability, and a record for refunds |
| Base price box per screening in the upcoming schedule (S$5 to S$60, 50-cent steps); paid bookings keep their price; open short-lists for that screening are re-priced | Staff can change ticket prices after scheduling |

New files: `includes/booking_admin.php`, `sql/05_booking_adjustments.sql`.
Updated: `admin.php`, `admin_action.php`, `includes/mailer.php`, `includes/auth.php`,
`sql/01_schema.sql`.

### Automated tests (`tests/`)

| File | Covers |
|---|---|
| `run_tests.php` | 42 cases across F1 to F10, security, double sales, email, staff tools and the rolling schedule. Needs only XAMPP; uses a throw-away database and catches emails locally |
| `ui_tests.js` | 6 browser cases: JavaScript validation, seat picker, keyboard use, phone layout (Playwright) |
| `test-results.md`, `ui-test-results.md` | Result tables in the report's format: input, expected output, method, result |

Result: 42/42 and 6/6 passed.

### Bugs fixed

| Bug | Fix |
|---|---|
| A form posted with no CSRF token was accepted when the visitor's session had no token yet (empty compared equal to empty). Found by test TC13 | `csrf_check()` in `includes/functions.php` now rejects empty tokens |
| Rolling schedule first version stopped filling the week if staff scheduled one screening further ahead | Now checks each of the next 7 days individually |

### To set up on an existing database

Import `cinesphere/sql/05_booking_adjustments.sql` once (adds one table; nothing
else is touched).

## 10 October 2026

This branch improves the existing PHP/MySQL prototype. It keeps the native HTML,
CSS, JavaScript and PHP approach used for the coursework base version.

### Original prototype

The original project already provided movie listings and details, screening
selection, a seat map, ranked booking preferences, simulated payment, booking
confirmation, customer accounts and a staff administration area. Those features
form the foundation of this version; this update focuses on usability and
correctness rather than replacing the application.

### Improvements

| Area | Change and benefit |
|---|---|
| Mobile navigation | Accessible toggle button with Enter and Escape support, focus restoration, and visible navigation when JavaScript is disabled. |
| Seat selection | Full seat labels and prices for assistive technology, arrow-key and Home/End navigation, one Tab stop, live totals, and a native checkbox fallback. |
| Phone layout | Seat controls retain a 44px target size. The seat map scrolls inside its container without making the whole page overflow. |
| Booking guidance | Three visible progress steps and clearer wording explain that a shortlisted preference does not reserve seats. |
| Scheduling | Shared full date-time checks cover screenings across midnight and the 20-minute turnaround. Reopening a cancelled screening also checks for conflicts. |
| Booking integrity | Screening and preference locks protect booking commits; existing database seat uniqueness checks remain in place. |
| Cancellations | Transactional refunds and seat release, with history snapshots preserved and staff notifications sent after a successful commit. |
| Booking history | New seat-history storage keeps the original seat labels, types and prices after cancellation releases the seats. |
| Ticket validity | Cancelled booking references cannot display a valid confirmation ticket. |
| Email | PHP mail delivery uses a local SMTP inbox and the fixed coursework mailbox. Delivery failures are reported accurately. |
| Documentation and verification | Updated setup notes, a migration for existing databases, regression checks, and recorded browser evidence. |

### Database and local setup

- Existing installations should apply `cinesphere/sql/03_booking_history.sql`.
  It creates the history table and backfills seats that still exist. It cannot
  recover seats deleted by cancellations before this migration.
- Fresh installations use the updated schema in `cinesphere/sql/01_schema.sql`.
- Follow `cinesphere/README.md` for database and local SMTP configuration.
- The local PHP/MariaDB/Mailpit binaries, running database, inbox and backups are
  machine-specific runtime files and are not included in this repository.

### Verification

- All 20 recorded HTTP, database and local-email regression checks passed.
- PHP syntax checks and JavaScript syntax checks passed.
- Browser checks covered keyboard seat selection, mobile navigation, accessible
  seat names and contained horizontal scrolling at a 390px viewport.
- See `tests/README.md`, `tests/base-results.json` and `tests/ui-results.json`.
  The regression script requires the sibling portable runtime described in the
  test README. These checks do not establish exhaustive concurrency coverage.

### Remaining coursework

Modern enhancements such as a component-framework frontend and live seat updates
are not implemented in this branch. The coursework report still needs the
requirements analysis, storyboard, wireframes and implementation discussion.
Payment remains simulated.
