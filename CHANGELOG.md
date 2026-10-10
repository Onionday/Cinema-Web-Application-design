# Changes to the CineSphere base version

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
