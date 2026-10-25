<?php
/* =====================================================================
 * tests/run_tests.php - CineSphere automated test suite
 *
 * Run from the project folder (the one that contains cinesphere/):
 *
 *     Windows / XAMPP:  C:\xampp\php\php.exe tests\run_tests.php
 *     Linux / macOS:    php tests/run_tests.php
 *
 * What it does
 *   1. Creates a throw-away database (cinesphere_test_xxxxxxxx) and loads
 *      sql/01_schema.sql and sql/02_seed.sql into it. Your real
 *      `cinesphere` database is never read or changed.
 *   2. Starts PHP's built-in web server on a free port, pointed at this
 *      project and at the test database, plus a mail catcher so no email
 *      leaves the machine.
 *   3. Drives the site the way a browser would (GET pages, POST forms with
 *      the CSRF token, follow redirects, keep the session cookie) and
 *      checks both the page shown and the rows in the database.
 *   4. Prints a summary, writes tests/test-results.md (the table for the
 *      report), then stops the servers and drops the test database.
 *
 * Needs: MySQL/MariaDB running (XAMPP: start MySQL), the PHP CLI with the
 * mysqli extension. Database login defaults to XAMPP's root with no
 * password; override with TEST_DB_HOST, TEST_DB_USER, TEST_DB_PASS,
 * TEST_DB_PORT environment variables if yours differ.
 * ===================================================================== */

declare(strict_types=1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Singapore');   // same as includes/config.php

$ROOT    = dirname(__DIR__);
$APP     = $ROOT . '/cinesphere';
$DB_HOST = getenv('TEST_DB_HOST') ?: 'localhost';
$DB_USER = getenv('TEST_DB_USER') ?: 'root';
$DB_PASS = getenv('TEST_DB_PASS') ?: '';
$DB_PORT = (int) (getenv('TEST_DB_PORT') ?: 3306);
$TEST_DB = 'cinesphere_test_' . bin2hex(random_bytes(4));

const FEE = 0.80;                 // BOOKING_FEE in includes/config.php
const WEEKEND = 2.00;             // WEEKEND_SURCHARGE
const MAILBOX = 'coursework@cinesphere.local';

if (!is_file($APP . '/sql/01_schema.sql')) {
    fwrite(STDERR, "Run this from the project folder: cinesphere/sql/01_schema.sql was not found.\n");
    exit(1);
}

/* =====================================================================
 *  1. Test database
 * ===================================================================== */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, '', $DB_PORT);
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot connect to MySQL ({$e->getMessage()}). Start MySQL in XAMPP first.\n");
    exit(1);
}
$db->set_charset('utf8mb4');

/** Loads an SQL file into the test database instead of `cinesphere`. */
function load_sql(mysqli $db, string $file, string $name): void
{
    $sql = file_get_contents($file);
    $sql = preg_replace('/\b(DROP DATABASE IF EXISTS|CREATE DATABASE|USE)\s+`?cinesphere`?/i',
                        '$1 `' . $name . '`', $sql);
    $db->multi_query($sql);
    do {
        if ($r = $db->store_result()) {
            $r->free();
        }
    } while ($db->more_results() && $db->next_result());
}

$cleanup = [];
register_shutdown_function(function () use (&$cleanup) {
    foreach (array_reverse($cleanup) as $fn) {
        try { $fn(); } catch (Throwable $e) { /* best effort */ }
    }
});

load_sql($db, $APP . '/sql/01_schema.sql', $TEST_DB);
$cleanup[] = function () use ($db, $TEST_DB) { $db->query("DROP DATABASE IF EXISTS `$TEST_DB`"); };
load_sql($db, $APP . '/sql/02_seed.sql', $TEST_DB);
$db->select_db($TEST_DB);

/* =====================================================================
 *  2. Web server + mail catcher
 * ===================================================================== */
function free_port(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr($name, strrpos($name, ':') + 1);
}

function wait_for_port(int $port, float $seconds = 10): bool
{
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        $c = @fsockopen('127.0.0.1', $port, $no, $str, 0.3);
        if ($c) { fclose($c); return true; }
        usleep(150000);
    }
    return false;
}

function start(array $cmd, array $env, array &$cleanup)
{
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $p = proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
                   $pipes, null, $env, ['bypass_shell' => true]);
    if (!is_resource($p)) {
        fwrite(STDERR, "Could not start: " . implode(' ', $cmd) . "\n");
        exit(1);
    }
    $cleanup[] = function () use ($p) { proc_terminate($p); proc_close($p); };
    return $p;
}

$MAIL_FILE = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $TEST_DB . '-mail.txt';
@unlink($MAIL_FILE);
$cleanup[] = function () use ($MAIL_FILE) { @unlink($MAIL_FILE); };

$env = array_merge(getenv(), ['CINESPHERE_DB_NAME' => $TEST_DB]);
$phpArgs = [PHP_BINARY];
if (PHP_OS_FAMILY === 'Windows') {
    $mailPort = free_port();
    start([PHP_BINARY, __DIR__ . '/mail_sink.php', 'smtp', (string) $mailPort, $MAIL_FILE], $env, $cleanup);
    wait_for_port($mailPort, 5);
    array_push($phpArgs, '-d', 'SMTP=127.0.0.1', '-d', 'smtp_port=' . $mailPort,
               '-d', 'sendmail_from=bookings@cinesphere.local');
} else {
    array_push($phpArgs, '-d', 'sendmail_path=' . escapeshellarg(PHP_BINARY) . ' '
               . escapeshellarg(__DIR__ . '/mail_sink.php') . ' pipe ' . escapeshellarg($MAIL_FILE));
}
$webPort = free_port();
start(array_merge($phpArgs, ['-S', '127.0.0.1:' . $webPort, '-t', $ROOT]), $env, $cleanup);
if (!wait_for_port($webPort)) {
    fwrite(STDERR, "The PHP built-in server did not start.\n");
    exit(1);
}
$BASE = "http://127.0.0.1:$webPort/cinesphere/";

/* =====================================================================
 *  3. A tiny browser: keeps cookies, follows redirects, reads the token
 * ===================================================================== */
final class Browser
{
    private array $cookies = [];
    public string $url = '';
    public int $status = 0;

    public function __construct(private string $base) {}

    public function get(string $path): string
    {
        return $this->send('GET', $this->base . $path, null);
    }

    /** POSTs a form. Adds this session's CSRF token unless $withToken is false. */
    public function post(string $path, array $fields, bool $withToken = true): string
    {
        if ($withToken && !isset($fields['csrf'])) {
            $fields['csrf'] = $this->token();
        }
        return $this->send('POST', $this->base . $path, http_build_query($fields));
    }

    public function token(): string
    {
        $html = $this->get('contact.php');
        preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
        return $m[1] ?? '';
    }

    private function send(string $method, string $url, ?string $body, int $hops = 0): string
    {
        $headers = [];
        if ($this->cookies) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) { $pairs[] = "$k=$v"; }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
            'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 30,
        ]]);
        $html = (string) @file_get_contents($url, false, $ctx);
        $resp = $http_response_header ?? [];
        $this->status = (int) (preg_match('#HTTP/\S+\s+(\d+)#', $resp[0] ?? '', $m) ? $m[1] : 0);
        $location = null;
        foreach ($resp as $h) {
            if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $c)) {
                $this->cookies[$c[1]] = $c[2];
            }
            if (preg_match('/^Location:\s*(.+)$/i', $h, $l)) {
                $location = trim($l[1]);
            }
        }
        $this->url = $url;
        if ($location !== null && $hops < 6) {
            $next = preg_match('#^https?://#', $location) ? $location
                  : preg_replace('#[^/]*$#', '', strtok($url, '?#')) . $location;
            return $this->send('GET', strtok($next, '#'), null, $hops + 1);
        }
        return $html;
    }

    public function login(string $email, string $password): string
    {
        return $this->post('process_login.php', ['email' => $email, 'password' => $password]);
    }
}

/* =====================================================================
 *  Helpers used by the test cases
 * ===================================================================== */
function one(string $sql, array $params = []): ?array
{
    global $db;
    $st = $db->prepare($sql);
    if ($params) { $st->bind_param(str_repeat('s', count($params)), ...array_map('strval', $params)); }
    $st->execute();
    $r = $st->get_result();
    $row = $r ? $r->fetch_assoc() : null;
    $st->close();
    return $row ?: null;
}

function all(string $sql, array $params = []): array
{
    global $db;
    $st = $db->prepare($sql);
    if ($params) { $st->bind_param(str_repeat('s', count($params)), ...array_map('strval', $params)); }
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

function exec_sql(string $sql, array $params = []): int
{
    global $db;
    $st = $db->prepare($sql);
    if ($params) { $st->bind_param(str_repeat('s', count($params)), ...array_map('strval', $params)); }
    $st->execute();
    $n = $st->affected_rows;
    $st->close();
    return $n;
}

function has(string $html, string $text): bool
{
    return stripos(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'), $text) !== false;
}

/** A future open screening, `$daysAhead` days from today (in hall order). */
function screening(int $daysAhead, int $skip = 0): array
{
    $d = date('Y-m-d', strtotime("+$daysAhead days"));
    $row = one("SELECT st.*, h.hall_id FROM showtimes st JOIN halls h ON h.hall_id = st.hall_id
                 WHERE st.show_date = ? AND st.status = 'open' ORDER BY st.show_time, st.showtime_id
                 LIMIT 1 OFFSET $skip", [$d]);
    if (!$row) { throw new RuntimeException("No open screening on $d in the seed data."); }
    return $row;
}

/** First $n seat ids of the hall, in row/number order, after skipping $from. */
function seats_in(int $hallId, int $n, int $from = 0): array
{
    return array_map(fn($r) => (int) $r['seat_id'],
        all("SELECT seat_id FROM seats WHERE hall_id = ? ORDER BY seat_row, seat_number LIMIT $n OFFSET $from", [$hallId]));
}

/** What a seat costs at a screening: base + weekend surcharge + seat-type surcharge. */
function expected_seat_price(array $st, int $seatId): float
{
    $s = one('SELECT surcharge FROM seats WHERE seat_id = ?', [$seatId]);
    $weekend = (int) date('N', strtotime($st['show_date'])) >= 6 ? WEEKEND : 0;
    return round((float) $st['base_price'] + $weekend + (float) $s['surcharge'], 2);
}

/** Books seats the way a customer does: short-list, then confirm with an approved payment. */
function book(Browser $b, int $userId, int $showtimeId, array $seatIds): ?array
{
    $b->post('process_preference.php', ['showtime_id' => $showtimeId, 'seat_ids' => implode(',', $seatIds)]);
    $pref = one('SELECT pref_id FROM preferences WHERE user_id = ? AND showtime_id = ? ORDER BY pref_id DESC LIMIT 1',
                [$userId, $showtimeId]);
    if (!$pref) { return null; }
    $b->post('process_booking.php', ['confirm' => [$pref['pref_id']], 'gateway' => 'success', 'agree' => 1]);
    return one("SELECT * FROM bookings WHERE user_id = ? AND showtime_id = ? AND booking_status = 'confirmed'
                ORDER BY booking_id DESC LIMIT 1", [$userId, $showtimeId]);
}

function mails(): string
{
    global $MAIL_FILE;
    clearstatcache();
    return is_file($MAIL_FILE) ? (string) file_get_contents($MAIL_FILE) : '';
}

function clear_shortlist(int $userId): void
{
    exec_sql('DELETE FROM preferences WHERE user_id = ?', [$userId]);
}

/* Seeded accounts (see README) */
$demo  = (int) one("SELECT user_id FROM users WHERE email = 'demo@cinesphere.sg'")['user_id'];
$siti  = (int) one("SELECT user_id FROM users WHERE email = 'siti@cinesphere.sg'")['user_id'];

/* =====================================================================
 *  4. The test cases
 *
 *  Each case: ID, requirement, description, input data, expected output,
 *  test method, and a function that runs it and returns
 *  [passed?, what actually happened].
 * ===================================================================== */
$cases = [];
function tc(string $id, string $req, string $title, string $input, string $expected,
            string $method, callable $run): void
{
    global $cases;
    $cases[] = compact('id', 'req', 'title', 'input', 'expected', 'method', 'run');
}

const HTTP = 'Automated: HTTP request + page check';
const HTTPDB = 'Automated: HTTP form post + database check';

/* ---------------- F1 / F2 / R4: pages, films, schedule --------------- */

tc('TC01', 'R4, Base', 'Every public page loads with its own title',
   'GET index, movies, movie?id=1, showtimes, booking?showtime=<first>, contact, account',
   'HTTP 200, a <title> containing "CineSphere", no PHP warning or error on the page', HTTP,
   function () {
       $b = new Browser($GLOBALS['BASE']);
       $st = screening(1);
       $bad = [];
       foreach (['index.php', 'movies.php', 'movie.php?id=1', 'showtimes.php',
                 'booking.php?showtime=' . $st['showtime_id'], 'contact.php', 'account.php'] as $p) {
           $h = $b->get($p);
           if ($b->status !== 200 || !preg_match('#<title>[^<]*CineSphere#', $h)
               || preg_match('/(Warning|Fatal error|Notice|Deprecated):/', $h)) {
               $bad[] = "$p ({$b->status})";
           }
       }
       return [!$bad, $bad ? 'Problems: ' . implode(', ', $bad) : '7 pages: all 200 with titles, no PHP errors'];
   });

tc('TC02', 'F1', 'Filter films by genre',
   'GET movies.php?genre=Drama&status=all', 'Only films whose genre contains "Drama" are listed', HTTPDB,
   function () {
       $h = (new Browser($GLOBALS['BASE']))->get('movies.php?genre=Drama&status=all');
       $drama = all("SELECT title FROM movies WHERE genre LIKE '%Drama%' AND status <> 'archived'");
       $other = all("SELECT title FROM movies WHERE genre NOT LIKE '%Drama%' AND status <> 'archived'");
       $missing = array_filter($drama, fn($m) => !has($h, $m['title']));
       $leaked  = array_filter($other, fn($m) => preg_match('#<h[23][^>]*>\s*(<a[^>]*>)?\s*' . preg_quote(htmlspecialchars($m['title']), '#') . '#i', $h));
       return [!$missing && !$leaked && $drama,
               count($drama) . ' Drama films shown; ' . count($leaked) . ' non-Drama titles shown as cards'];
   });

tc('TC03', 'F1', 'Film keyword search with no match',
   'GET movies.php?q=zzqqxx', 'Friendly "Nothing matched that" message, no film cards', HTTP,
   function () {
       $h = (new Browser($GLOBALS['BASE']))->get('movies.php?q=zzqqxx');
       return [has($h, 'Nothing matched that'), has($h, 'Nothing matched that') ? 'Empty-state message shown' : 'No empty-state message'];
   });

tc('TC04', 'F1', 'Film details page lists the film\'s screenings',
   'GET movie.php?id=<film with screenings>', 'Synopsis and a "Book"/seat link for that film\'s screenings', HTTP,
   function () {
       $st = screening(1);
       $h = (new Browser($GLOBALS['BASE']))->get('movie.php?id=' . $st['movie_id']);
       $n = preg_match_all('/booking\.php\?showtime=\d+/', $h);
       return [$n > 0, "$n screening links on the film page"];
   });

tc('TC05', 'F2', 'Schedule filtered by venue',
   'GET showtimes.php?cinema=1 (CineSphere Orchard)', 'Every row shown is at Orchard; table not empty', HTTPDB,
   function () {
       $h = (new Browser($GLOBALS['BASE']))->get('showtimes.php?cinema=1');
       preg_match_all('/booking\.php\?showtime=(\d+)/', $h, $m);
       $ids = array_unique($m[1]);
       $wrong = 0;
       foreach ($ids as $id) {
           $r = one('SELECT h.cinema_id FROM showtimes s JOIN halls h ON h.hall_id = s.hall_id WHERE s.showtime_id = ?', [$id]);
           if ((int) $r['cinema_id'] !== 1) { $wrong++; }
       }
       return [count($ids) > 0 && $wrong === 0, count($ids) . " screenings listed, $wrong at another venue"];
   });

tc('TC06', 'F2, R9', 'Schedule date outside the selling week',
   'GET showtimes.php?date=<today + 30 days>', 'Date ignored with a warning; full week still shown', HTTP,
   function () {
       $h = (new Browser($GLOBALS['BASE']))->get('showtimes.php?date=' . date('Y-m-d', strtotime('+30 days')));
       $ok = has($h, 'outside the week') && preg_match('/booking\.php\?showtime=/', $h);
       return [(bool) $ok, $ok ? 'Warning shown and week listed' : 'Warning or listing missing'];
   });

/* ---------------- F3 / R7 / R9: accounts and validation ------------- */

$newUser = ['full_name' => 'Tan Wei Ming', 'email' => 'weiming.test@example.com', 'phone' => '+65 9123 4567',
            'birth_date' => '2000-05-17', 'password' => 'Movie#2026', 'confirm' => 'Movie#2026', 'terms' => 1];

tc('TC07', 'F3, R7', 'Register with valid details',
   'Name "Tan Wei Ming", email weiming.test@example.com, phone +65 9123 4567, born 2000-05-17, password Movie#2026 (twice), terms ticked',
   'Account created and signed in; password stored as a bcrypt hash, never as plain text', HTTPDB,
   function () use ($newUser) {
       $b = new Browser($GLOBALS['BASE']);
       $h = $b->post('process_register.php', $newUser);
       $u = one('SELECT password_hash FROM users WHERE email = ?', [$newUser['email']]);
       $hashed = $u && str_starts_with($u['password_hash'], '$2y$') && $u['password_hash'] !== $newUser['password'];
       return [$hashed && has($h, 'Welcome to CineSphere'),
               $u ? 'Row created, hash starts ' . substr($u['password_hash'], 0, 7) . '…, welcome message ' . (has($h, 'Welcome') ? 'shown' : 'missing') : 'No row created'];
   });

tc('TC08', 'F3, R9', 'Register with passwords that do not match (JavaScript bypassed)',
   'Valid details but password Movie#2026 / confirm Movie#2027, posted directly to the server',
   'Rejected by PHP; no account created; form shows an error', HTTPDB,
   function () use ($newUser) {
       $d = array_merge($newUser, ['email' => 'mismatch@example.com', 'confirm' => 'Movie#2027']);
       $h = (new Browser($GLOBALS['BASE']))->post('process_register.php', $d);
       $u = one('SELECT user_id FROM users WHERE email = ?', [$d['email']]);
       return [!$u && has($h, 'could not create the account'), $u ? 'Account was created' : 'Rejected, no row'];
   });

tc('TC09', 'F3, R9', 'Register with invalid email and phone',
   'email "not-an-email", phone "12ab"', 'Rejected with "valid email" / "valid contact number" messages; no row', HTTPDB,
   function () use ($newUser) {
       $d = array_merge($newUser, ['email' => 'not-an-email', 'phone' => '12ab']);
       $h = (new Browser($GLOBALS['BASE']))->post('process_register.php', $d);
       $n = (int) one("SELECT COUNT(*) n FROM users WHERE email = 'not-an-email'")['n'];
       $ok = $n === 0 && has($h, 'valid email') && has($h, 'valid contact number');
       return [$ok, "Rows created: $n; messages " . ($ok ? 'shown' : 'missing')];
   });

tc('TC10', 'F3, R9', 'Register someone younger than 13',
   'birth_date = today minus 10 years', 'Rejected: "must be at least 13 years old"', HTTPDB,
   function () use ($newUser) {
       $d = array_merge($newUser, ['email' => 'young@example.com', 'birth_date' => date('Y-m-d', strtotime('-10 years'))]);
       $h = (new Browser($GLOBALS['BASE']))->post('process_register.php', $d);
       $u = one("SELECT user_id FROM users WHERE email = 'young@example.com'");
       return [!$u && has($h, 'at least 13'), $u ? 'Account was created' : 'Rejected with age message'];
   });

tc('TC11', 'F3', 'Register an email that already exists',
   'email demo@cinesphere.sg (seeded account)', 'Rejected: "already an account with that email"', HTTPDB,
   function () use ($newUser) {
       $d = array_merge($newUser, ['email' => 'demo@cinesphere.sg']);
       $h = (new Browser($GLOBALS['BASE']))->post('process_register.php', $d);
       $n = (int) one("SELECT COUNT(*) n FROM users WHERE email = 'demo@cinesphere.sg'")['n'];
       return [$n === 1 && has($h, 'already an account'), "Accounts with that email: $n"];
   });

tc('TC12', 'F3, R7', 'Sign in with wrong then correct password',
   'demo@cinesphere.sg with "Wrong#123", then with "Demo@123"',
   'First: vague "Sign-in failed" message, not signed in. Second: "Signed in as demo@cinesphere.sg"', HTTP,
   function () {
       $b = new Browser($GLOBALS['BASE']);
       $bad = $b->login('demo@cinesphere.sg', 'Wrong#123');
       $good = $b->login('demo@cinesphere.sg', 'Demo@123');
       $ok = has($bad, 'Sign-in failed') && has($good, 'Signed in as demo@cinesphere.sg');
       return [$ok, $ok ? 'Wrong password refused; correct password signs in' : 'Unexpected sign-in behaviour'];
   });

tc('TC13', 'R7', 'Form posted without a CSRF token',
   'Registration POST with valid data but no csrf field: (a) from a brand-new visitor, (b) from a visitor who has already opened a page',
   'Both refused ("session expired"); no account created', HTTPDB,
   function () use ($newUser) {
       $fresh = new Browser($GLOBALS['BASE']);
       $h1 = $fresh->post('process_register.php', array_merge($newUser, ['email' => 'csrf1@example.com']), false);
       $known = new Browser($GLOBALS['BASE']);
       $known->get('account.php');
       $h2 = $known->post('process_register.php', array_merge($newUser, ['email' => 'csrf2@example.com']), false);
       $n = (int) one("SELECT COUNT(*) n FROM users WHERE email IN ('csrf1@example.com', 'csrf2@example.com')")['n'];
       $ok = $n === 0 && has($h1, 'session expired') && has($h2, 'session expired');
       return [$ok, $n ? "$n account(s) created without a token" : 'Both refused, no rows'];
   });

tc('TC14', 'R7', 'SQL injection in the sign-in form',
   "email: ' OR '1'='1' -- , password: x", 'Not signed in; no database error shown', HTTP,
   function () {
       $b = new Browser($GLOBALS['BASE']);
       $h = $b->login("' OR '1'='1' -- ", 'x');
       $ok = !has($h, 'Signed in as') && !preg_match('/SQL|mysqli|syntax/i', strip_tags($h));
       return [$ok, $ok ? 'Treated as a wrong address; no SQL error' : 'Injection had an effect'];
   });

/* ---------------- F4 / F5: seat map and short-list ------------------ */

tc('TC15', 'F4', 'Seat map marks sold seats as unavailable',
   'Siti books seat A1 of a screening; anyone opens booking.php for that screening',
   'Seat A1 is rendered disabled with "already booked"', HTTPDB,
   function () use ($siti) {
       $st = screening(2);
       $seat = seats_in((int) $st['hall_id'], 1)[0];
       $s = new Browser($GLOBALS['BASE']);
       $s->login('siti@cinesphere.sg', 'Demo@123');
       book($s, $siti, (int) $st['showtime_id'], [$seat]);
       $h = (new Browser($GLOBALS['BASE']))->get('booking.php?showtime=' . $st['showtime_id']);
       $ok = (bool) preg_match('/disabled\s+data-seat="' . $seat . '"/', $h) && str_contains($h, ' - already booked');
       return [$ok, $ok ? 'Seat button disabled and labelled already booked' : 'Seat still selectable'];
   });

tc('TC16', 'F5', 'Add two seats to the short-list',
   'demo user; 2 Standard seats at a screening tomorrow', 'One preference row with 2 seats; total = seat prices + S$0.80 fee each', HTTPDB,
   function () use ($demo) {
       clear_shortlist($demo);
       $st = screening(1);
       $seats = seats_in((int) $st['hall_id'], 2, 20);
       $b = new Browser($GLOBALS['BASE']);
       $b->login('demo@cinesphere.sg', 'Demo@123');
       $h = $b->post('process_preference.php', ['showtime_id' => $st['showtime_id'], 'seat_ids' => implode(',', $seats)]);
       $p = one('SELECT seat_count, est_total FROM preferences WHERE user_id = ? AND showtime_id = ?', [$demo, $st['showtime_id']]);
       $exp = round(expected_seat_price($st, $seats[0]) + expected_seat_price($st, $seats[1]) + 2 * FEE, 2);
       $ok = $p && (int) $p['seat_count'] === 2 && abs((float) $p['est_total'] - $exp) < 0.001 && has($h, 'Added to your short-list');
       return [$ok, $p ? "2 seats, total S$" . number_format((float) $p['est_total'], 2) . " (expected S$" . number_format($exp, 2) . ')' : 'No preference row'];
   });

tc('TC17', 'F4, R9', 'More than 8 seats in one selection',
   '9 seat ids posted for one screening', 'Refused: "at most 8"; no preference row', HTTPDB,
   function () use ($demo) {
       clear_shortlist($demo);
       $st = screening(1, 1);
       $b = new Browser($GLOBALS['BASE']);
       $b->login('demo@cinesphere.sg', 'Demo@123');
       $h = $b->post('process_preference.php', ['showtime_id' => $st['showtime_id'],
                     'seat_ids' => implode(',', seats_in((int) $st['hall_id'], 9, 30))]);
       $p = one('SELECT pref_id FROM preferences WHERE user_id = ?', [$demo]);
       return [!$p && has($h, 'at most 8'), $p ? 'Preference was saved' : 'Refused with limit message'];
   });

tc('TC18', 'F5', 'Seventh short-list entry',
   '6 entries already on the short-list, then a 7th', 'Refused with the 6-entry limit; still 6 rows', HTTPDB,
   function () use ($demo) {
       clear_shortlist($demo);
       $b = new Browser($GLOBALS['BASE']);
       $b->login('demo@cinesphere.sg', 'Demo@123');
       for ($i = 0; $i < 7; $i++) {
           $st = screening(3, $i);
           $h = $b->post('process_preference.php', ['showtime_id' => $st['showtime_id'],
                         'seat_ids' => (string) seats_in((int) $st['hall_id'], 1, 40)[0]]);
       }
       $n = (int) one('SELECT COUNT(*) n FROM preferences WHERE user_id = ?', [$demo])['n'];
       return [$n === 6 && has($h, 'already holds 6'), "Entries after 7 attempts: $n"];
   });

tc('TC19', 'F5', 'Re-order and remove short-list entries',
   'Move entry #2 up, then remove it', 'Ranks swap (UPDATE), then the entry is deleted and ranks close up', HTTPDB,
   function () use ($demo) {
       $rows = all('SELECT pref_id FROM preferences WHERE user_id = ? ORDER BY rank_order', [$demo]);
       $second = $rows[1]['pref_id'];
       $b = new Browser($GLOBALS['BASE']);
       $b->login('demo@cinesphere.sg', 'Demo@123');
       $b->post('remove_preference.php', ['pref_id' => $second, 'action' => 'up']);
       $rank = (int) one('SELECT rank_order FROM preferences WHERE pref_id = ?', [$second])['rank_order'];
       $b->post('remove_preference.php', ['pref_id' => $second, 'action' => 'remove']);
       $gone = !one('SELECT pref_id FROM preferences WHERE pref_id = ?', [$second]);
       $ranks = array_map(fn($r) => (int) $r['rank_order'], all('SELECT rank_order FROM preferences WHERE user_id = ? ORDER BY rank_order', [$demo]));
       $ok = $rank === 1 && $gone && $ranks === range(1, count($ranks));
       clear_shortlist($demo);
       return [$ok, "After move-up rank=$rank; removed=" . ($gone ? 'yes' : 'no') . '; remaining ranks ' . implode(',', $ranks)];
   });

/* ---------------- F6 / F7 / R8 / R12: checkout and email ------------ */

tc('TC20', 'F6', 'Payment declined',
   'One short-list entry, gateway = declined', 'A "failed" booking is logged; no seats allocated; short-list unchanged', HTTPDB,
   function () use ($demo) {
       clear_shortlist($demo);
       $st = screening(4);
       $seats = seats_in((int) $st['hall_id'], 1, 50);
       $b = new Browser($GLOBALS['BASE']);
       $b->login('demo@cinesphere.sg', 'Demo@123');
       $b->post('process_preference.php', ['showtime_id' => $st['showtime_id'], 'seat_ids' => (string) $seats[0]]);
       $pref = one('SELECT pref_id FROM preferences WHERE user_id = ?', [$demo]);
       $h = $b->post('process_booking.php', ['confirm' => [$pref['pref_id']], 'gateway' => 'declined', 'agree' => 1]);
       $failed = one("SELECT booking_id FROM bookings WHERE user_id = ? AND showtime_id = ? AND booking_status = 'failed'", [$demo, $st['showtime_id']]);
       $held = one('SELECT COUNT(*) n FROM booking_seats WHERE showtime_id = ? AND seat_id = ?', [$st['showtime_id'], $seats[0]]);
       $still = one('SELECT pref_id FROM preferences WHERE pref_id = ?', [$pref['pref_id']]);
       $ok = $failed && (int) $held['n'] === 0 && $still && has($h, 'declined');
       return [$ok, 'failed row ' . ($failed ? 'yes' : 'no') . ', seats held ' . $held['n'] . ', short-list kept ' . ($still ? 'yes' : 'no')];
   });

tc('TC21', 'F6, F7', 'Payment approved',
   'Same entry, gateway = success, conditions ticked',
   'Confirmed + paid booking; seats in booking_seats; entry leaves short-list; e-ticket page shows the reference', HTTPDB,
   function () use ($demo) {
       $pref = one('SELECT pref_id, showtime_id FROM preferences WHERE user_id = ?', [$demo]);
       $b = new Browser($GLOBALS['BASE']);
       $b->login('demo@cinesphere.sg', 'Demo@123');
       $h = $b->post('process_booking.php', ['confirm' => [$pref['pref_id']], 'gateway' => 'success', 'agree' => 1]);
       $bk = one("SELECT * FROM bookings WHERE user_id = ? AND showtime_id = ? AND booking_status = 'confirmed'", [$demo, $pref['showtime_id']]);
       $seats = $bk ? (int) one('SELECT COUNT(*) n FROM booking_seats WHERE booking_id = ?', [$bk['booking_id']])['n'] : 0;
       $left = one('SELECT pref_id FROM preferences WHERE pref_id = ?', [$pref['pref_id']]);
       $ok = $bk && $bk['payment_status'] === 'paid' && $seats === 1 && !$left && str_contains($b->url, 'confirmation.php') && has($h, $bk['booking_ref']);
       $GLOBALS['tc21_ref'] = $bk['booking_ref'] ?? '';
       return [$ok, $bk ? "Booking {$bk['booking_ref']} confirmed/paid, $seats seat, e-ticket shown" : 'No confirmed booking'];
   });

tc('TC22', 'F7, R12', 'Acknowledgement email for a confirmed booking',
   'The booking from TC21', 'One email containing the booking reference, addressed only to coursework@cinesphere.local', 'Automated: local mail catcher',
   function () {
       $ref = $GLOBALS['tc21_ref'] ?? '';
       $all = mails();
       $msgs = array_filter(explode("=====END=====", $all), fn($m) => $ref !== '' && str_contains($m, $ref));
       $msg = reset($msgs) ?: '';
       $ok = $msg !== '' && stripos($msg, MAILBOX) !== false && !preg_match('/To:.*demo@cinesphere\.sg/i', $msg);
       return [$ok, $msg ? 'Email captured for ' . $ref . ', addressed to ' . MAILBOX : 'No email captured for ' . $ref];
   });

tc('TC23', 'F6, R8', 'Two customers confirm the same seat',
   'demo and siti both short-list seat B5 of one screening; siti confirms first, then demo',
   'Siti gets the seat; demo is refused ("seats were taken while you were deciding"); the seat is sold once', HTTPDB,
   function () use ($demo, $siti) {
       clear_shortlist($demo); clear_shortlist($siti);
       $st = screening(5);
       $seat = seats_in((int) $st['hall_id'], 1, 16)[0];
       $a = new Browser($GLOBALS['BASE']); $a->login('demo@cinesphere.sg', 'Demo@123');
       $s = new Browser($GLOBALS['BASE']); $s->login('siti@cinesphere.sg', 'Demo@123');
       $a->post('process_preference.php', ['showtime_id' => $st['showtime_id'], 'seat_ids' => (string) $seat]);
       book($s, $siti, (int) $st['showtime_id'], [$seat]);
       $pref = one('SELECT pref_id FROM preferences WHERE user_id = ?', [$demo]);
       $h = $a->post('process_booking.php', ['confirm' => [$pref['pref_id']], 'gateway' => 'success', 'agree' => 1]);
       $sold = (int) one('SELECT COUNT(*) n FROM booking_seats WHERE showtime_id = ? AND seat_id = ?', [$st['showtime_id'], $seat])['n'];
       $demoGot = one("SELECT booking_id FROM bookings WHERE user_id = ? AND showtime_id = ? AND booking_status='confirmed'", [$demo, $st['showtime_id']]);
       $ok = $sold === 1 && !$demoGot && has($h, 'seats were taken');
       clear_shortlist($demo);
       return [$ok, "Seat sold $sold time(s); second customer " . ($demoGot ? 'ALSO got it' : 'refused')];
   });

/* ---------------- F8: history and cancellation ---------------------- */

tc('TC24', 'F8', 'Customer cancels more than 2 hours before',
   'demo cancels a booking for a screening 2 days ahead', 'Booking cancelled + refunded; seat back on sale; listed under cancelled', HTTPDB,
   function () use ($demo) {
       $st = screening(2, 1);
       $seat = seats_in((int) $st['hall_id'], 1, 60)[0];
       $b = new Browser($GLOBALS['BASE']); $b->login('demo@cinesphere.sg', 'Demo@123');
       $bk = book($b, $demo, (int) $st['showtime_id'], [$seat]);
       $h = $b->post('cancel_booking.php', ['booking_id' => $bk['booking_id']]);
       $now = one('SELECT booking_status, payment_status FROM bookings WHERE booking_id = ?', [$bk['booking_id']]);
       $held = (int) one('SELECT COUNT(*) n FROM booking_seats WHERE booking_id = ?', [$bk['booking_id']])['n'];
       $ok = $now['booking_status'] === 'cancelled' && $now['payment_status'] === 'refunded' && $held === 0 && has($h, 'has been cancelled');
       return [$ok, "Status {$now['booking_status']}/{$now['payment_status']}, seats still held: $held"];
   });

tc('TC25', 'F8', 'Customer tries to cancel within 2 hours',
   'A booking for a screening starting in 60 minutes', 'Refused with the two-hour rule; booking stays confirmed', HTTPDB,
   function () use ($demo) {
       $start = time() + 3600;
       $hall = (int) one('SELECT hall_id FROM halls ORDER BY hall_id DESC LIMIT 1')['hall_id'];
       exec_sql("INSERT INTO showtimes (movie_id, hall_id, show_date, show_time, format, base_price) VALUES (1, ?, ?, ?, '2D', 10)",
                [$hall, date('Y-m-d', $start), date('H:i:s', $start)]);
       $sid = (int) $GLOBALS['db']->insert_id;
       exec_sql("INSERT INTO bookings (booking_ref, user_id, showtime_id, seat_count, total_amount, booking_status, payment_status)
                 VALUES ('CSSOON0001', ?, ?, 1, 10.80, 'confirmed', 'paid')", [$demo, $sid]);
       $bid = (int) $GLOBALS['db']->insert_id;
       $b = new Browser($GLOBALS['BASE']); $b->login('demo@cinesphere.sg', 'Demo@123');
       $h = $b->post('cancel_booking.php', ['booking_id' => $bid]);
       $s = one('SELECT booking_status FROM bookings WHERE booking_id = ?', [$bid])['booking_status'];
       return [$s === 'confirmed' && has($h, 'two hours'), "Status after attempt: $s"];
   });

tc('TC26', 'F8, R7', 'Customer tries to cancel someone else\'s booking',
   'demo posts cancel for one of siti\'s booking ids', 'Refused ("not found on your account"); booking unchanged', HTTPDB,
   function () use ($siti) {
       $bk = one("SELECT booking_id FROM bookings WHERE user_id = ? AND booking_status = 'confirmed' LIMIT 1", [$siti]);
       $b = new Browser($GLOBALS['BASE']); $b->login('demo@cinesphere.sg', 'Demo@123');
       $h = $b->post('cancel_booking.php', ['booking_id' => $bk['booking_id']]);
       $s = one('SELECT booking_status FROM bookings WHERE booking_id = ?', [$bk['booking_id']])['booking_status'];
       return [$s === 'confirmed' && has($h, 'not found on your account'), "Siti's booking is still $s"];
   });

/* ---------------- F9: contact form ---------------------------------- */

$contact = ['name' => 'Aisha Rahman', 'email' => 'aisha@example.com', 'phone' => '+65 8123 4567',
            'subject' => 'Feedback', 'rating' => 5, 'message' => 'The seat map made choosing seats very easy, thank you!'];

tc('TC27', 'F9', 'Send valid feedback',
   'Aisha Rahman, aisha@example.com, +65 8123 4567, Feedback, rating 5, 54-character message',
   'Stored with status "new"; reference #CM… shown', HTTPDB,
   function () use ($contact) {
       $h = (new Browser($GLOBALS['BASE']))->post('process_contact.php', $contact);
       $m = one("SELECT msg_status FROM contact_messages WHERE email = 'aisha@example.com'");
       return [$m && $m['msg_status'] === 'new' && has($h, 'reference #CM'), $m ? 'Stored as ' . $m['msg_status'] : 'Not stored'];
   });

tc('TC28', 'F9, R9', 'Feedback message too short and invalid subject',
   'message "Too short", subject "Hacking"', 'Rejected; nothing stored', HTTPDB,
   function () use ($contact) {
       $d = array_merge($contact, ['email' => 'short@example.com', 'message' => 'Too short', 'subject' => 'Hacking']);
       $h = (new Browser($GLOBALS['BASE']))->post('process_contact.php', $d);
       $m = one("SELECT msg_id FROM contact_messages WHERE email = 'short@example.com'");
       return [!$m && has($h, 'has not been sent'), $m ? 'Stored anyway' : 'Rejected, not stored'];
   });

tc('TC29', 'R7', 'Script typed into feedback is shown as text to staff',
   'message containing <script>alert(1)</script>', 'Staff page shows &lt;script&gt; escaped, never a live script tag', HTTPDB,
   function () use ($contact) {
       $d = array_merge($contact, ['email' => 'xss@example.com', 'message' => 'Hello <script>alert(1)</script> this is a test message']);
       (new Browser($GLOBALS['BASE']))->post('process_contact.php', $d);
       $a = new Browser($GLOBALS['BASE']); $a->login('admin@cinesphere.sg', 'Admin@123');
       $h = $a->get('admin.php?msgs=all');
       $ok = str_contains($h, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($h, '<script>alert(1)</script>');
       return [$ok, $ok ? 'Escaped as text' : 'Raw script tag found in page'];
   });

/* ---------------- F10: staff tools ---------------------------------- */

tc('TC30', 'F10, R7', 'Customer opens the staff page',
   'Signed in as demo (customer), GET admin.php', 'Access refused; staff tools not shown', HTTP,
   function () {
       $b = new Browser($GLOBALS['BASE']); $b->login('demo@cinesphere.sg', 'Demo@123');
       $h = $b->get('admin.php');
       $ok = !has($h, 'Staff tools') && !str_contains($b->url, 'admin.php');
       return [$ok, $ok ? 'Redirected away from admin.php' : 'Staff page shown to a customer'];
   });

tc('TC31', 'F10', 'Schedule a screening that overlaps another in the same hall',
   'Same hall and date as an existing screening, starting 30 minutes after it', 'Rejected: "The hall is busy with …"', HTTPDB,
   function () {
       $st = screening(3);
       $t = date('H:i', strtotime($st['show_time']) + 1800);
       $a = new Browser($GLOBALS['BASE']); $a->login('admin@cinesphere.sg', 'Admin@123');
       $before = (int) one('SELECT COUNT(*) n FROM showtimes')['n'];
       $h = $a->post('admin_action.php', ['action' => 'add_showtime', 'movie_id' => 1, 'hall_id' => $st['hall_id'],
                     'show_date' => $st['show_date'], 'show_time' => $t, 'format' => '2D', 'base_price' => '12.00']);
       $after = (int) one('SELECT COUNT(*) n FROM showtimes')['n'];
       return [$after === $before && has($h, 'hall is busy'), "Rows before/after: $before/$after"];
   });

tc('TC32', 'F10, R9', 'Schedule a screening while venues are closed',
   'Start time 06:00, 30 days ahead', 'Rejected: "closed between 03:00 and 10:00"', HTTPDB,
   function () {
       $a = new Browser($GLOBALS['BASE']); $a->login('admin@cinesphere.sg', 'Admin@123');
       $h = $a->post('admin_action.php', ['action' => 'add_showtime', 'movie_id' => 1, 'hall_id' => 1,
                     'show_date' => date('Y-m-d', strtotime('+30 days')), 'show_time' => '06:00', 'format' => '2D', 'base_price' => '12.00']);
       return [has($h, 'closed between'), has($h, 'closed between') ? 'Rejected with opening-hours message' : 'Accepted'];
   });

tc('TC33', 'F10', 'Schedule a valid screening',
   'Film 1, hall 1, 30 days ahead at 20:00, 2D, S$12.00', 'New open screening row (INSERT); success message', HTTPDB,
   function () {
       $d = date('Y-m-d', strtotime('+30 days'));
       $a = new Browser($GLOBALS['BASE']); $a->login('admin@cinesphere.sg', 'Admin@123');
       $h = $a->post('admin_action.php', ['action' => 'add_showtime', 'movie_id' => 1, 'hall_id' => 1,
                     'show_date' => $d, 'show_time' => '20:00', 'format' => '2D', 'base_price' => '12.00']);
       $r = one("SELECT status FROM showtimes WHERE hall_id = 1 AND show_date = ? AND show_time = '20:00:00'", [$d]);
       return [$r && $r['status'] === 'open' && has($h, 'scheduled'), $r ? 'Row created' : 'No row'];
   });

tc('TC34', 'F10', 'Staff cancel a screening that has bookings',
   'The screening Siti booked in TC15', 'Screening cancelled; its bookings refunded; seats released', HTTPDB,
   function () use ($siti) {
       $bk = one("SELECT booking_id, showtime_id FROM bookings WHERE user_id = ? AND booking_status = 'confirmed' ORDER BY booking_id LIMIT 1", [$siti]);
       $a = new Browser($GLOBALS['BASE']); $a->login('admin@cinesphere.sg', 'Admin@123');
       $a->post('admin_action.php', ['action' => 'cancel_showtime', 'showtime_id' => $bk['showtime_id']]);
       $st = one('SELECT status FROM showtimes WHERE showtime_id = ?', [$bk['showtime_id']])['status'];
       $b = one('SELECT booking_status, payment_status FROM bookings WHERE booking_id = ?', [$bk['booking_id']]);
       $held = (int) one('SELECT COUNT(*) n FROM booking_seats WHERE showtime_id = ?', [$bk['showtime_id']])['n'];
       $ok = $st === 'cancelled' && $b['booking_status'] === 'cancelled' && $b['payment_status'] === 'refunded' && $held === 0;
       return [$ok, "Screening $st; booking {$b['booking_status']}/{$b['payment_status']}; seats held $held"];
   });

/* ---------------- F10: staff booking tools and price ---------------- */

tc('TC35', 'F10', 'Staff refund 1 of 3 seats',
   'demo books 3 seats; staff refund the middle seat with reason "One guest cannot come"',
   'Booking keeps 2 seats; total drops by that seat\'s price + S$0.80; seat back on sale; change log row; customer emailed', HTTPDB,
   function () use ($demo) {
       clear_shortlist($demo);
       $st = screening(6);
       $seats = seats_in((int) $st['hall_id'], 3, 70);
       $b = new Browser($GLOBALS['BASE']); $b->login('demo@cinesphere.sg', 'Demo@123');
       $bk = book($b, $demo, (int) $st['showtime_id'], $seats);
       $price = (float) one('SELECT seat_price FROM booking_seats WHERE booking_id = ? AND seat_id = ?', [$bk['booking_id'], $seats[1]])['seat_price'];
       $a = new Browser($GLOBALS['BASE']); $a->login('admin@cinesphere.sg', 'Admin@123');
       $a->post('admin_action.php', ['action' => 'refund_seats', 'booking_id' => $bk['booking_id'],
                'seat_ids' => [$seats[1]], 'reason' => 'One guest cannot come']);
       $now = one('SELECT seat_count, total_amount FROM bookings WHERE booking_id = ?', [$bk['booking_id']]);
       $free = !one('SELECT 1 x FROM booking_seats WHERE showtime_id = ? AND seat_id = ?', [$st['showtime_id'], $seats[1]]);
       $log = one("SELECT amount_change FROM booking_adjustments WHERE booking_id = ? AND action = 'seat_refund'", [$bk['booking_id']]);
       $exp = round((float) $bk['total_amount'] - $price - FEE, 2);
       $ok = (int) $now['seat_count'] === 2 && abs((float) $now['total_amount'] - $exp) < 0.001 && $free && $log
             && abs((float) $log['amount_change'] + $price + FEE) < 0.001 && str_contains(mails(), $bk['booking_ref'] . ' updated');
       $GLOBALS['tc35'] = ['booking' => $bk, 'seats' => $seats, 'st' => $st];
       return [$ok, "Seats {$bk['seat_count']}→{$now['seat_count']}, total S\${$bk['total_amount']}→S\${$now['total_amount']} (expected S$" . number_format($exp, 2) . '), log ' . ($log ? $log['amount_change'] : 'missing')];
   });

tc('TC36', 'F10', 'Staff move a seat to a different seat type',
   'Move the first seat of the TC35 booking to a free Premium seat', 'Seat swapped on the e-ticket; customer charged only the surcharge difference; logged', HTTPDB,
   function () {
       ['booking' => $bk, 'seats' => $seats, 'st' => $st] = $GLOBALS['tc35'];
       $from = one('SELECT s.surcharge, bs.seat_price FROM booking_seats bs JOIN seats s ON s.seat_id = bs.seat_id WHERE bs.booking_id = ? AND bs.seat_id = ?', [$bk['booking_id'], $seats[0]]);
       $to = one("SELECT s.seat_id, s.surcharge FROM seats s WHERE s.hall_id = ? AND s.surcharge > ?
                   AND s.seat_id NOT IN (SELECT seat_id FROM booking_seats WHERE showtime_id = ?) ORDER BY s.seat_id LIMIT 1",
                 [$st['hall_id'], $from['surcharge'], $st['showtime_id']]);
       if (!$to) { return [false, 'No seat with a higher surcharge in this hall to test with']; }
       $before = (float) one('SELECT total_amount FROM bookings WHERE booking_id = ?', [$bk['booking_id']])['total_amount'];
       $a = new Browser($GLOBALS['BASE']); $a->login('admin@cinesphere.sg', 'Admin@123');
       $a->post('admin_action.php', ['action' => 'move_seat', 'booking_id' => $bk['booking_id'],
                'from_seat' => $seats[0], 'to_seat' => $to['seat_id'], 'reason' => 'Asked for a better view']);
       $after = (float) one('SELECT total_amount FROM bookings WHERE booking_id = ?', [$bk['booking_id']])['total_amount'];
       $diff = round((float) $to['surcharge'] - (float) $from['surcharge'], 2);
       $onTicket = one('SELECT 1 x FROM booking_seat_history WHERE booking_id = ? AND seat_id = ?', [$bk['booking_id'], $to['seat_id']]);
       $ok = abs($after - $before - $diff) < 0.001 && $onTicket;
       $GLOBALS['tc36_to'] = (int) $to['seat_id'];
       return [$ok, 'Total S$' . number_format($before, 2) . '→S$' . number_format($after, 2) . ' (difference S$' . number_format($diff, 2) . '), e-ticket ' . ($onTicket ? 'updated' : 'not updated')];
   });

tc('TC37', 'F10, R8', 'Staff move a seat onto a seat already sold',
   'Move the remaining third seat onto the seat it was moved to in TC36', 'Refused; nothing changes', HTTPDB,
   function () {
       ['booking' => $bk, 'seats' => $seats] = $GLOBALS['tc35'];
       $a = new Browser($GLOBALS['BASE']); $a->login('admin@cinesphere.sg', 'Admin@123');
       $h = $a->post('admin_action.php', ['action' => 'move_seat', 'booking_id' => $bk['booking_id'],
                     'from_seat' => $seats[2], 'to_seat' => $GLOBALS['tc36_to'], 'reason' => 'Should be refused']);
       $still = one('SELECT 1 x FROM booking_seats WHERE booking_id = ? AND seat_id = ?', [$bk['booking_id'], $seats[2]]);
       return [$still && has($h, 'has just been sold'), $still ? 'Refused, seat unchanged' : 'Seat was moved'];
   });

tc('TC38', 'F10, R9', 'Staff change without a proper reason',
   'Cancel the TC35 booking with reason "no"', 'Refused: reason needs at least 5 characters; booking still confirmed', HTTPDB,
   function () {
       $bk = $GLOBALS['tc35']['booking'];
       $a = new Browser($GLOBALS['BASE']); $a->login('admin@cinesphere.sg', 'Admin@123');
       $h = $a->post('admin_action.php', ['action' => 'cancel_booking', 'booking_id' => $bk['booking_id'], 'reason' => 'no']);
       $s = one('SELECT booking_status FROM bookings WHERE booking_id = ?', [$bk['booking_id']])['booking_status'];
       return [$s === 'confirmed' && has($h, 'at least 5 characters'), "Booking still $s"];
   });

tc('TC39', 'F10', 'Staff cancel a whole booking',
   'Cancel the TC35 booking with reason "Customer called the box office"', 'Cancelled + refunded in full; all seats released; logged with the full amount', HTTPDB,
   function () {
       $bk = $GLOBALS['tc35']['booking'];
       $total = (float) one('SELECT total_amount FROM bookings WHERE booking_id = ?', [$bk['booking_id']])['total_amount'];
       $a = new Browser($GLOBALS['BASE']); $a->login('admin@cinesphere.sg', 'Admin@123');
       $a->post('admin_action.php', ['action' => 'cancel_booking', 'booking_id' => $bk['booking_id'], 'reason' => 'Customer called the box office']);
       $b = one('SELECT booking_status, payment_status FROM bookings WHERE booking_id = ?', [$bk['booking_id']]);
       $held = (int) one('SELECT COUNT(*) n FROM booking_seats WHERE booking_id = ?', [$bk['booking_id']])['n'];
       $log = one("SELECT amount_change FROM booking_adjustments WHERE booking_id = ? AND action = 'booking_cancel'", [$bk['booking_id']]);
       $ok = $b['booking_status'] === 'cancelled' && $b['payment_status'] === 'refunded' && $held === 0 && $log && abs((float) $log['amount_change'] + $total) < 0.001;
       return [$ok, "{$b['booking_status']}/{$b['payment_status']}, seats held $held, logged " . ($log['amount_change'] ?? 'nothing')];
   });

tc('TC40', 'F10, R9', 'Staff change a screening\'s ticket price',
   'Base price S$12.30 (not a 50-cent step), then S$18.50, on a screening that is on a customer\'s short-list',
   'S$12.30 refused. S$18.50 saved; short-list total re-priced to the new price; existing bookings keep the price paid', HTTPDB,
   function () use ($demo) {
       clear_shortlist($demo);
       $st = screening(5, 2);
       $seat = seats_in((int) $st['hall_id'], 1, 80)[0];
       $b = new Browser($GLOBALS['BASE']); $b->login('demo@cinesphere.sg', 'Demo@123');
       $b->post('process_preference.php', ['showtime_id' => $st['showtime_id'], 'seat_ids' => (string) $seat]);
       $a = new Browser($GLOBALS['BASE']); $a->login('admin@cinesphere.sg', 'Admin@123');
       $h1 = $a->post('admin_action.php', ['action' => 'set_price', 'showtime_id' => $st['showtime_id'], 'base_price' => '12.30']);
       $p1 = (float) one('SELECT base_price FROM showtimes WHERE showtime_id = ?', [$st['showtime_id']])['base_price'];
       $a->post('admin_action.php', ['action' => 'set_price', 'showtime_id' => $st['showtime_id'], 'base_price' => '18.50']);
       $st2 = one('SELECT * FROM showtimes WHERE showtime_id = ?', [$st['showtime_id']]);
       $pref = one('SELECT est_total FROM preferences WHERE user_id = ? AND showtime_id = ?', [$demo, $st['showtime_id']]);
       $exp = round(expected_seat_price($st2, $seat) + FEE, 2);
       $ok = has($h1, 'steps of') && abs($p1 - (float) $st['base_price']) < 0.001
             && abs((float) $st2['base_price'] - 18.5) < 0.001 && abs((float) $pref['est_total'] - $exp) < 0.001;
       clear_shortlist($demo);
       return [$ok, 'S$12.30 ' . (has($h1, 'steps of') ? 'refused' : 'accepted') . '; price now S$' . $st2['base_price']
                    . '; short-list total S$' . $pref['est_total'] . ' (expected S$' . number_format($exp, 2) . ')'];
   });

/* ---------------- Rolling schedule ---------------------------------- */

tc('TC41', 'F2', 'Schedule tops itself up when the data is old',
   'Move every screening 14 days into the past (as if the seed was imported two weeks ago), then open the schedule',
   'Screenings exist for each of the next 7 days; the page lists them', HTTPDB,
   function () {
       exec_sql('UPDATE showtimes SET show_date = DATE_SUB(show_date, INTERVAL 14 DAY)');
       $h = (new Browser($GLOBALS['BASE']))->get('showtimes.php');
       $days = all('SELECT show_date, COUNT(*) n FROM showtimes WHERE show_date BETWEEN ? AND ? GROUP BY show_date',
                   [date('Y-m-d'), date('Y-m-d', strtotime('+6 days'))]);
       $listed = preg_match_all('/booking\.php\?showtime=\d+/', $h);
       return [count($days) === 7 && $listed > 0, count($days) . " of 7 days have screenings; $listed bookable rows on the page"];
   });

tc('TC42', 'F2', 'Topping up never duplicates screenings',
   'Open the schedule three more times', 'Number of screenings unchanged; no two in the same hall at the same time', HTTPDB,
   function () {
       $before = (int) one('SELECT COUNT(*) n FROM showtimes')['n'];
       $b = new Browser($GLOBALS['BASE']);
       for ($i = 0; $i < 3; $i++) { $b->get('showtimes.php'); }
       $after = (int) one('SELECT COUNT(*) n FROM showtimes')['n'];
       $dups = (int) one('SELECT COUNT(*) n FROM (SELECT hall_id, show_date, show_time FROM showtimes GROUP BY 1,2,3 HAVING COUNT(*) > 1) d')['n'];
       return [$before === $after && $dups === 0, "Screenings {$before}→{$after}, duplicates {$dups}"];
   });

/* =====================================================================
 *  5. Run, report, write tests/test-results.md
 * ===================================================================== */
$started = microtime(true);
$results = [];
$pass = 0;
echo "\nCineSphere test suite - database $TEST_DB, server 127.0.0.1:$webPort\n\n";
foreach ($cases as $c) {
    try {
        [$ok, $actual] = ($c['run'])();
    } catch (Throwable $e) {
        $ok = false;
        $actual = 'Error: ' . $e->getMessage();
    }
    $ok = (bool) $ok;
    $pass += $ok ? 1 : 0;
    $results[] = $c + ['ok' => $ok, 'actual' => $actual];
    printf("  %s  %-5s %-62s %s\n", $ok ? 'PASS' : 'FAIL', $c['id'], mb_strimwidth($c['title'], 0, 62, '…'), $ok ? '' : "\n         -> $actual");
}
$total = count($cases);
$secs = round(microtime(true) - $started, 1);
echo "\n  $pass of $total passed in {$secs}s\n";

$cell = fn(string $s) => str_replace(["|", "\n"], ['\\|', ' '], $s);
$md  = "# CineSphere test results\n\n";
$md .= "Generated by `tests/run_tests.php` on " . date('j M Y, g:i A') . " (PHP " . PHP_VERSION . ", "
     . $db->server_info . ", " . PHP_OS_FAMILY . ").\n\n";
$md .= "**$pass of $total test cases passed.** Each run uses a fresh throw-away database loaded from "
     . "`sql/01_schema.sql` and `sql/02_seed.sql`; emails are caught locally and never sent.\n\n";
$md .= "| ID | Req. | Test case | Input data | Expected output | Test method | Test result |\n";
$md .= "| --- | --- | --- | --- | --- | --- | --- |\n";
foreach ($results as $r) {
    $md .= '| ' . implode(' | ', array_map($cell, [$r['id'], $r['req'], $r['title'], $r['input'], $r['expected'], $r['method'],
            ($r['ok'] ? 'PASS' : 'FAIL') . ' - ' . $r['actual']])) . " |\n";
}
file_put_contents(__DIR__ . '/test-results.md', $md);
echo "  Results table written to tests/test-results.md\n\n";

exit($pass === $total ? 0 : 1);
