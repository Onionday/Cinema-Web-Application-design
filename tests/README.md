# CineSphere tests

Two automated suites cover every functional requirement (F1 to F10) and the
application requirements that can be tested (R4 to R9, R12). Both print
PASS/FAIL and write a results table in the report's test case format:
**input data, expected output, test method, test result**.

| File | What it is |
|---|---|
| `run_tests.php` | 42 server-side test cases (TC01 to TC42). Pages, forms, database, email, security |
| `ui_tests.js` | 6 browser test cases (UI01 to UI06). JavaScript validation, seat picker, phone layout |
| `mail_sink.php` | Helper for `run_tests.php`: catches the site's emails so nothing is sent |
| `test-results.md` | Latest results of `run_tests.php`, ready to paste into the report |
| `ui-test-results.md` | Latest results of `ui_tests.js` |
| `base_regression.py`, `base-results.json`, `ui-results.json` | Earlier regression checks (10 Oct), kept for reference; see the end of this file |

## 1. Server-side suite: `run_tests.php` (needs only XAMPP)

1. Start **MySQL** in the XAMPP Control Panel (Apache is not needed for this suite).
2. Open a terminal in the project folder (the one that contains `cinesphere` and `tests`):

   ```
   cd C:\xampp\htdocs\Cinema-Web-Application-design
   C:\xampp\php\php.exe tests\run_tests.php
   ```

3. Read the summary, then open `tests/test-results.md`.

It is safe to run at any time. Every run:

- creates a throw-away database `cinesphere_test_xxxxxxxx`, loads `01_schema.sql` and
  `02_seed.sql` into it, and **drops it at the end**. Your real `cinesphere` database
  is never read or changed;
- starts PHP's built-in web server on a free port, pointed at that database;
- catches every email in a temporary file, so nothing is delivered anywhere;
- takes about 5 seconds.

If your MySQL root account has a password, set it first:
`set TEST_DB_PASS=yourpassword` (also `TEST_DB_USER`, `TEST_DB_HOST`, `TEST_DB_PORT`).

**Test method.** Each case behaves like a browser: it opens pages, posts forms with
the session's CSRF token, follows redirects and keeps the session cookie. It then
checks two things: what the page shows (messages, links, escaped text) and what is
in the database (rows created, statuses, totals, seats held, change-log entries).

## 2. Browser suite: `ui_tests.js` (optional, needs Node.js)

The server-side suite cannot run JavaScript, so these cases use a real browser.

```
npm install playwright
npx playwright install chromium
node tests\ui_tests.js http://localhost/Cinema-Web-Application-design/cinesphere/
```

Apache and MySQL must be running, with the seed imported. It signs in as the demo
customer but does not book anything.

**Without Node.js**, do the same six checks by hand in Chrome and record the result:

| ID | Steps | Expected |
|---|---|---|
| UI01 | Register tab: fill every field, password `Movie#2026`, confirm `Movie#2027`, press Create account | Error under Confirm: passwords do not match; page does not submit |
| UI02 | Register tab: date of birth 10 years ago, click elsewhere | Error: must be at least 13 years old |
| UI03 | Sign in as demo, open any screening's seat map, click two free seats | Total = both seat prices + S$0.80 each |
| UI04 | On the seat map, click a seat, then press the Right arrow key | Focus moves to the next seat |
| UI05 | Dev tools (F12) → device toolbar → 390 px wide; tap the menu button, press Escape | Menu opens, then closes; no sideways scrolling |
| UI06 | Contact page: valid details, message `Too short`, press Send | Error: at least 20 characters; page does not submit |

## 3. What is covered

| Requirement | Test cases |
|---|---|
| F1 Browse films | TC02, TC03, TC04 |
| F2 Weekly schedule (including rolling schedule) | TC05, TC06, TC41, TC42 |
| F3 Register, sign in | TC07 to TC12, UI01, UI02 |
| F4 Seat map | TC15, TC17, UI03, UI04 |
| F5 Ranked short-list | TC16, TC18, TC19 |
| F6 Final selection and payment | TC20, TC21, TC23 |
| F7 Confirmation and email | TC21, TC22 |
| F8 History and cancellation | TC24, TC25, TC26 |
| F9 Contact and feedback | TC27, TC28, UI06 |
| F10 Staff tools (scheduling, booking changes, prices) | TC30 to TC40 |
| R4 Consistent pages and titles | TC01 |
| R5, R6 Responsive and keyboard accessible | UI04, UI05 |
| R7 Security (hashing, CSRF, SQL injection, XSS, access control) | TC07, TC12, TC13, TC14, TC26, TC29, TC30 |
| R8 No double sale | TC23, TC37 |
| R9 Three-layer validation | TC06, TC08 to TC10, TC17, TC28, TC32, TC38, TC40, UI01, UI02, UI06 |
| R12 Email only to the local mailbox | TC22 |

**A bug these tests found.** TC13 showed that a form posted with no CSRF token was
accepted when the visitor's session had no token yet, because the check compared
an empty string with an empty string. `csrf_check()` in `includes/functions.php`
now rejects empty tokens. With the old code, TC13 fails; with the fix, all 42 pass.

## 4. Earlier regression checks (10 October)

`base_regression.py` needs Python and the sibling `CineSphere-local` portable runtime
(MariaDB and Mailpit). It uses its own database and PHP ports 18087/18088. Its 20
recorded results are in `base-results.json`, and the manual browser checks from that
round are in `ui-results.json`. The two suites above replace it for day-to-day use
because they only need XAMPP.
