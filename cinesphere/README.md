# CineSphere — a web portal for booking cinema tickets

IE4727 Web Application Design · **Theme 5** · Base Version

Built with HTML5, CSS3, vanilla JavaScript, PHP 8 and MySQL/MariaDB.
No framework, no template, no jQuery, no AJAX, no JSON — every page is
generated on the server and every style is hand-written.

---

## 1. Getting it running under XAMPP

1. **Copy the folder.** Put the whole `cinesphere` folder inside your
   XAMPP `htdocs` directory, so you end up with
   `C:\xampp\htdocs\cinesphere\index.php`.

2. **Start Apache and MySQL** from the XAMPP Control Panel.

3. **Import the database.** Open <http://localhost/phpmyadmin>, click
   *Import*, and run these two files **in order**:

   | Order | File | What it does |
   |---|---|---|
   | 1 | `sql/01_schema.sql` | Creates the `cinesphere` database and all eleven tables |
   | 2 | `sql/02_seed.sql` | Loads the sample films, halls, seats, schedule and bookings |

   From a terminal the equivalent is:

   ```
   mysql -u root -p < sql/01_schema.sql
   mysql -u root -p < sql/02_seed.sql
   ```

   The schedule is seeded relative to `CURDATE()`, so the week on display
   is always "this week" no matter when you import it. Re-run
   `02_seed.sql` at any time to reset everything back to a clean state.

4. **Check the credentials.** `includes/config.php` ships with the XAMPP
   defaults (`root`, blank password). Change `DB_USER` / `DB_PASS` there
   if yours differ.

5. **Open** <http://localhost/cinesphere/>.

### Sign-in details

| Role | Email | Password |
|---|---|---|
| Customer | `demo@cinesphere.sg` | `Demo@123` |
| Customer | `siti@cinesphere.sg` | `Demo@123` |
| Staff | `admin@cinesphere.sg` | `Admin@123` |

The staff account unlocks the **Staff** page in the navigation bar.

### Email

`MAIL_MODE` in `includes/config.php` is set to `'file'`. Every
acknowledgement is written into a `mail/` folder inside the project as a
readable `.eml` file rather than being sent out — which is what the
project guidelines require. The folder is created automatically the
first time a booking is confirmed. Setting `MAIL_MODE` to `'mail'`
switches to PHP's `mail()` instead.

---

## 2. What the application does

The theme asks for a portal that shows the films and the schedule, lets a
customer **make multiple bookings in order of preference**, presents the
list of those still available for **final selection of one or more**, and
emails an acknowledgement once confirmed. That flow is the spine of the
site:

1. **Browse** — filter the catalogue or the week's schedule by film,
   venue, date, time of day and format.
2. **Choose seats** — an interactive seat map of the actual hall, showing
   which seats are genuinely gone.
3. **Short-list** — the screening plus those seats joins a ranked
   short-list. Up to six alternatives can be held at once, reordered, or
   removed. Nothing is charged at this point.
4. **Final selection** — the short-list page re-checks every entry against
   the live bookings table, shows which survived, and the customer ticks
   one or more to confirm.
5. **Payment** — a simulated third-party gateway, with both the approved
   and the declined path fully handled.
6. **Acknowledgement** — e-tickets on screen and an email per booking.

Bookings can be cancelled from *My bookings* up to two hours before the
screening, which releases the seats back on sale immediately.

---

## 3. Meeting the Base Version checklist

| Requirement | Where it is met |
|---|---|
| 1 home page + 4–10 content pages | Home plus **10** content pages — see the table below |
| Text and images on every page | Original SVG artwork for all eight films plus site imagery in `images/` |
| Appropriate page titles | Each page sets `$page_title`; `includes/header.php` renders `<title>` |
| One table displaying content effectively | `showtimes.php` — the full weekly schedule, grouped by day, with live availability. Further tables appear on the home, confirmation, staff and short-list pages |
| One form, 4+ fields, server-side processed | `account.php` registration — 7 fields — processed by `process_register.php`. The contact form (`contact.php` → `process_contact.php`) has 6 |
| SQL `SELECT`, `INSERT`, `UPDATE` | See §5 |
| One server-side generated page | Every page is; `mybookings.php` and `confirmation.php` are built entirely from query results |
| JavaScript validation where HTML5 cannot | `js/validate.js` — password composition, password match, age ≥ 13, message length, cross-field rules |
| Server-side validation in PHP | `includes/functions.php` (`v_name`, `v_email`, `v_phone`, `v_password`, `v_birthdate`) plus per-handler checks |
| Form on the project site | All forms post to local `.php` scripts |
| External style sheet, 4+ styles | `css/style.css` — one file, ~1,000 lines, 18 documented sections |
| **No** mailto/handler form actions | Every `action` is a local PHP script |
| **No** frames or iframes | None used |
| **No** jQuery / JSON / AJAX | Plain DOM only; every submission is a normal form post |
| **No** templates or CSS frameworks | Everything hand-written |
| **No** image-only pages | Every page carries substantial text |
| **No** external site links | Only internal links |

### The eleven pages

| # | File | What it is |
|---|---|---|
| — | `index.php` | Home — featured film, quick search, now showing, next screenings, venues |
| 1 | `movies.php` | Catalogue with keyword, genre, rating, status and sort filters |
| 2 | `movie.php` | One film: details, cast, and its schedule grouped by day and venue |
| 3 | `showtimes.php` | The full weekly schedule table |
| 4 | `booking.php` | Interactive seat map for one screening |
| 5 | `preferences.php` | The ranked short-list and the final selection |
| 6 | `confirmation.php` | E-tickets for a completed transaction |
| 7 | `mybookings.php` | Booking history: upcoming, past, cancelled and declined |
| 8 | `account.php` | Sign in / register / edit your details |
| 9 | `contact.php` | Venue information and the feedback form |
| 10 | `admin.php` | Staff tools: schedule screenings, manage the catalogue, triage feedback |

Form handlers (`process_*.php`, `cancel_booking.php`, `logout.php`,
`admin_action.php`, `remove_preference.php`) process and redirect; they
render no markup, so they are not counted as pages.

---

## 4. Folder layout

```
cinesphere/
├── index.php                  home page
├── movies.php  movie.php  showtimes.php  booking.php
├── preferences.php  confirmation.php  mybookings.php
├── account.php  contact.php  admin.php
│
├── process_register.php       form handlers (no output, redirect only)
├── process_login.php
├── process_account.php
├── process_preference.php
├── process_booking.php
├── process_contact.php
├── remove_preference.php
├── cancel_booking.php
├── admin_action.php
├── logout.php
│
├── includes/
│   ├── config.php             credentials, business rules, session
│   ├── db.php                 mysqli connection + prepared-statement helpers
│   ├── functions.php          escaping, validation, pricing, seat queries
│   ├── auth.php               sessions, password hashing, page gates
│   ├── mailer.php             booking / cancellation / contact acknowledgements
│   ├── header.php             <head>, navigation, flash messages
│   └── footer.php             footer and script tags
│
├── css/style.css              the single external style sheet
├── js/
│   ├── main.js                navigation, two-step confirms, running totals
│   ├── validate.js            client-side form validation
│   └── seats.js               the seat picker
│
├── images/                    22 original SVG files
├── sql/
│   ├── 01_schema.sql          database and tables
│   └── 02_seed.sql            sample data
└── mail/                      created at run time; acknowledgement .eml files
```

---

## 5. Database

Eleven tables plus one view.

```
users ──┬── preferences ──── preference_seats ──┐
        │        │                              │
        └── bookings ─────── booking_seats ──── seats ──── halls ──── cinemas
                 │                    │                       │
              showtimes ──────────────┴───────────────────────┘
                 │
              movies                     contact_messages
```

* **`v_showtime_availability`** is a view that joins a screening to its
  film, hall and venue and counts the seats already sold, so the same
  availability arithmetic is not repeated in four different pages.
* **`booking_seats`** carries `UNIQUE (showtime_id, seat_id)`. That key,
  not any PHP check, is what makes selling the same seat twice
  impossible — the second `INSERT` fails and its transaction rolls back.
* Cancelling **updates** the booking to `cancelled` and **deletes** its
  `booking_seats` rows, so the customer keeps the record while the seats
  return to sale.

### Where each SQL command appears

| Command | Examples |
|---|---|
| `SELECT` | Every page. Joins across up to five tables in `functions.php`, aggregate subqueries on `index.php` and `admin.php` |
| `INSERT` | Registration (`process_register.php`), short-list entries (`process_preference.php`), bookings and their seats (`process_booking.php`), feedback (`process_contact.php`), new screenings (`admin_action.php`) |
| `UPDATE` | Profile edits (`process_account.php`), `last_login` (`auth.php`), booking cancellation (`cancel_booking.php`), short-list reordering (`remove_preference.php`), catalogue status, screening cancellation and feedback triage (`admin_action.php`) |
| `DELETE` | Removing a short-list entry, releasing seats on cancellation |

Everything runs through prepared statements in `includes/db.php` — no
user-supplied value is ever concatenated into SQL.

---

## 6. Validation

Three layers, deliberately saying the same thing:

1. **HTML5** — `required`, `type`, `minlength`, `maxlength`, `pattern`,
   `min`, `max`, `step`. Free, instant, and works without JavaScript.
2. **JavaScript** (`js/validate.js`) — the rules HTML5 cannot express:
   password composition, "both passwords match", age at least 13,
   minimum message length, and a live password-strength meter. Fields are
   checked on blur and re-checked as you type once they are already wrong.
3. **PHP** — the same rules again in `includes/functions.php`. These are
   the ones that decide whether anything is written, because the first two
   can be bypassed entirely.

On top of that, every handler checks a CSRF token, refuses anything that
is not a `POST`, and re-reads ownership from the database before touching
a row — a customer cannot reorder, confirm or cancel anything that is not
theirs.

---

## 7. Security notes

* Passwords stored with `password_hash()` (bcrypt); never in plain text.
* All output escaped through `e()` before it reaches the page.
* CSRF token on every state-changing form.
* Session id regenerated on sign-in and sign-out.
* Sign-in failures are rate-limited and give a deliberately vague message.
* No card details are collected, transmitted or stored anywhere.

---

## 8. Things worth showing in the demo

* Add the same seats to the short-list from two different browsers and
  confirm in both — the second one is refused cleanly and nothing is
  double-sold.
* Choose **Payment declined** on the short-list page: nothing is booked,
  the short-list is untouched, and the attempt is recorded and visible
  under *My bookings → Cancelled & declined*.
* Cancel a booking and watch its seats reappear as available on the seat
  map.
* On the Staff page, try to schedule two films in the same hall at
  overlapping times, or a screening at 06:00 — both are refused with a
  reason.
* Turn JavaScript off: every form still validates and still works,
  because the server does the real checking.

---

## 9. Still to do (the Additional Version, 15%)

The base version is complete on its own. The modern-enhancement
component would build on it — for example by replacing the front end with
a component framework, moving the seat map to a live WebSocket feed so
two customers see each other's selections in real time, or swapping PHP
sessions for token-based auth. None of that is present here, and none of
it is needed for the 85% the base version carries.
