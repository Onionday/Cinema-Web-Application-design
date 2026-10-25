# CineSphere: a web portal for booking cinema tickets

IE4727 Web Application Design · Theme 5 (cinema ticket booking) · Base Version

CineSphere is a ticket booking site for a fictional Singapore cinema chain with
three venues: Orchard, Jewel Changi and HarbourFront. Customers browse films and
the weekly schedule and pick seats on a map of the real hall. They short-list up
to six screenings in order of preference, then confirm one or more of them in a
single checkout. Each confirmed booking produces an e-ticket and an email
acknowledgement. Staff schedule screenings, set ticket prices, manage customer
bookings and answer feedback.

Built by hand with **HTML5, CSS3, JavaScript, PHP 8 and MySQL**: no framework,
template, jQuery, AJAX or JSON, as the Base Version rules require.

---

## Quick start (XAMPP)

1. Put this folder in `C:\xampp\htdocs\` (it is already at
   `C:\xampp\htdocs\Cinema-Web-Application-design`).
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. In phpMyAdmin (<http://localhost/phpmyadmin>) → **Import**, run in order:
   `cinesphere/sql/01_schema.sql`, then `cinesphere/sql/02_seed.sql`.
4. Open <http://localhost/Cinema-Web-Application-design/cinesphere/>.

Already have the database from an earlier version? Don't re-import; that wipes
it. Import only the upgrade you are missing:

| File | Adds |
|---|---|
| `sql/03_booking_history.sql` | Seat history kept after cancellations (10 Oct) |
| `sql/05_booking_adjustments.sql` | Change log for the staff booking tools (26 Oct) |

### Accounts in the sample data

| Role | Email | Password |
|---|---|---|
| Customer | `demo@cinesphere.sg` | `Demo@123` |
| Customer | `siti@cinesphere.sg` | `Demo@123` |
| Staff | `admin@cinesphere.sg` | `Admin@123` |

The schedule looks after itself: the site keeps the next seven days filled with
screenings, so you never need to re-import the seed just to get current dates.

Booking emails go only to the local coursework mailbox, as the guideline
requires. To see them, run a local SMTP inbox such as Mailpit; setup is in
`cinesphere/README.md`. Without one, bookings still work and the site says the
email could not be delivered.

---

## What it does

| # | Feature | Where |
|---|---|---|
| F1 | Browse films; filter by keyword, genre, rating, status; film details with its screenings | `movies.php`, `movie.php` |
| F2 | Weekly schedule table with live seat availability; filter by film, venue, date, time, format | `showtimes.php` |
| F3 | Register (7 fields), sign in, edit account | `account.php` |
| F4 | Interactive seat map of the actual hall, with live price total and keyboard support | `booking.php` |
| F5 | Ranked short-list of up to 6 choices; reorder or remove; no seat is held yet | `preferences.php` |
| F6 | Final selection of one or more choices; simulated payment (approved and declined both handled) | `preferences.php` → `process_booking.php` |
| F7 | E-ticket page and an acknowledgement email per booking | `confirmation.php` |
| F8 | Booking history; customer cancellation up to 2 hours before the film | `mybookings.php` |
| F9 | Venue information and a feedback form | `contact.php` |
| F10 | Staff tools: schedule screenings (clash-checked), set ticket prices, cancel or reopen screenings, manage the film catalogue, refund or move seats in a customer's booking, answer feedback | `admin.php` |

Home page plus **10 content pages** (the maximum the guideline allows), **13
database tables** plus one view, one external style sheet, and three
JavaScript files.

### Points worth knowing

- **No seat is ever sold twice.** Every booking is written in a transaction that
  locks the screening first. On top of that, the database has a `UNIQUE
  (showtime_id, seat_id)` rule, so even two clicks at the same instant cannot
  both succeed.
- **Validation in three layers**: HTML5 attributes, then JavaScript
  (`js/validate.js`) for rules HTML5 can't express, then PHP, which makes the
  final decision.
- **Security:** bcrypt password hashing, prepared statements for every query,
  all output escaped, a CSRF token on every form, and staff pages limited to
  the staff role.
- **Staff changes are audited.** Every refund, seat move or staff cancellation
  needs a reason and emails the customer. It is also recorded in
  `booking_adjustments` with who made it and the amount.

---

## Folder layout

```
Cinema-Web-Application-design/
├── README.md                this file
├── CHANGELOG.md             what changed, and when
├── cinesphere/              the web application
│   ├── *.php                11 pages + form handlers (process_*.php, admin_action.php, …)
│   ├── includes/            config, database, auth, validation, mail, scheduling,
│   │                        staff booking tools (booking_admin.php),
│   │                        rolling schedule (rolling_schedule.php)
│   ├── css/style.css        the single external style sheet
│   ├── js/                  main.js, validate.js, seats.js
│   ├── images/              original SVG artwork for every film
│   ├── sql/                 01 schema, 02 sample data, 03 and 05 upgrades, 04 clean-up
│   └── README.md            detailed technical notes (checklist, database, validation)
└── tests/                   automated test suites and their results
```

---

## Testing

```
cd C:\xampp\htdocs\Cinema-Web-Application-design
C:\xampp\php\php.exe tests\run_tests.php
```

- **42 server-side test cases.** They cover every feature, security and data
  integrity. They run in about 5 seconds against a temporary database that is
  deleted afterwards, so your real data is never touched. Results are written
  to `tests/test-results.md` as a table in the report's format (input data,
  expected output, test method, test result).
- **6 browser test cases** (`tests/ui_tests.js`, needs Node.js and Playwright)
  for the JavaScript validation, seat picker and phone layout. Without Node.js,
  the same checks can be done by hand from `tests/README.md`.

Latest run: **42/42 and 6/6 passed** (26 Oct 2026).

---

## Not included

- **Real payment.** It is simulated, as the project brief allows; refunds are
  recorded, not sent to a payment provider.
- **The Additional Version (15%)**, i.e. modern enhancements such as a
  component framework or live seat updates.

See `CHANGELOG.md` for the history of the work.
