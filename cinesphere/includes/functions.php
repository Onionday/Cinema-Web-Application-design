<?php
/* =====================================================================
 * includes/functions.php
 *
 * Shared helpers: output escaping, CSRF protection, flash messages,
 * validation routines, pricing arithmetic and the seat-availability
 * queries that several pages need.
 * ===================================================================== */

require_once __DIR__ . '/db.php';

/* ------------------------------------------------------------------ */
/*  Output                                                             */
/* ------------------------------------------------------------------ */

/** Escape for HTML output. Used on every single echoed variable. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** S$12.50 */
function money($amount): string
{
    return CURRENCY . number_format((float) $amount, 2);
}

/** 2026-09-21 -> Sun, 21 Sep 2026 */
function pretty_date(string $ymd): string
{
    $ts = strtotime($ymd);
    return $ts ? date('D, j M Y', $ts) : $ymd;
}

/** 19:45:00 -> 7:45 PM */
function pretty_time(string $hms): string
{
    $ts = strtotime($hms);
    return $ts ? date('g:i A', $ts) : $hms;
}

/** 141 -> 2h 21m */
function pretty_runtime(int $minutes): string
{
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
}

/** A5 */
function seat_label(array $seat): string
{
    return $seat['seat_row'] . $seat['seat_number'];
}

/* ------------------------------------------------------------------ */
/*  Navigation / redirects                                             */
/* ------------------------------------------------------------------ */

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

/* ------------------------------------------------------------------ */
/*  Flash messages                                                     */
/* ------------------------------------------------------------------ */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ------------------------------------------------------------------ */
/*  Form state carried across a redirect (old input + field errors)    */
/* ------------------------------------------------------------------ */

function stash_form(string $key, array $errors, array $old): void
{
    $_SESSION['form'][$key] = ['errors' => $errors, 'old' => $old];
}

function take_form(string $key): array
{
    $f = $_SESSION['form'][$key] ?? ['errors' => [], 'old' => []];
    unset($_SESSION['form'][$key]);
    return $f;
}

/* ------------------------------------------------------------------ */
/*  CSRF                                                               */
/* ------------------------------------------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Aborts the request if the posted token does not match the session. */
function csrf_check(): void
{
    $posted = $_POST['csrf'] ?? '';
    if (!is_string($posted) || !hash_equals($_SESSION['csrf'] ?? '', $posted)) {
        http_response_code(400);
        flash('error', 'Your session expired before the form was submitted. Please try again.');
        redirect('index.php');
    }
}

/** Every handler starts with this: POST only, valid token. */
function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('index.php');
    }
    csrf_check();
}

/* ------------------------------------------------------------------ */
/*  Server-side validation                                             */
/*                                                                     */
/*  These mirror the HTML5 attributes and the JavaScript in            */
/*  js/validate.js. Client-side checks are a convenience; these are    */
/*  the ones that actually decide whether data reaches the database.   */
/* ------------------------------------------------------------------ */

function v_text(string $raw): string
{
    return trim(preg_replace('/\s+/u', ' ', $raw));
}

function v_name(string $raw, string $label, array &$errors, string $field): string
{
    $val = v_text($raw);
    if ($val === '') {
        $errors[$field] = "$label is required.";
    } elseif (mb_strlen($val) < 3 || mb_strlen($val) > 80) {
        $errors[$field] = "$label must be between 3 and 80 characters.";
    } elseif (!preg_match("/^[\p{L}][\p{L}\p{M}'.\- ]*$/u", $val)) {
        $errors[$field] = "$label may only contain letters, spaces, apostrophes, full stops and hyphens.";
    }
    return $val;
}

function v_email(string $raw, array &$errors, string $field = 'email'): string
{
    $val = strtolower(v_text($raw));
    if ($val === '') {
        $errors[$field] = 'Email address is required.';
    } elseif (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
        $errors[$field] = 'That does not look like a valid email address.';
    } elseif (mb_strlen($val) > 120) {
        $errors[$field] = 'Email address is too long.';
    }
    return $val;
}

function v_phone(string $raw, array &$errors, string $field = 'phone'): string
{
    $val = v_text($raw);
    $digits = preg_replace('/\D/', '', $val);
    if ($val === '') {
        $errors[$field] = 'Contact number is required.';
    } elseif (!preg_match('/^\+?[0-9 \-()]{8,20}$/', $val) || strlen($digits) < 8) {
        $errors[$field] = 'Enter a valid contact number (at least 8 digits).';
    }
    return $val;
}

/**
 * Password policy, enforced identically in js/validate.js:
 * 8-64 characters, at least one letter, one digit and one symbol.
 */
function v_password(string $raw, array &$errors, string $field = 'password'): string
{
    if ($raw === '') {
        $errors[$field] = 'Password is required.';
    } elseif (strlen($raw) < 8 || strlen($raw) > 64) {
        $errors[$field] = 'Password must be 8 to 64 characters long.';
    } elseif (!preg_match('/[A-Za-z]/', $raw)
           || !preg_match('/[0-9]/', $raw)
           || !preg_match('/[^A-Za-z0-9]/', $raw)) {
        $errors[$field] = 'Password needs at least one letter, one number and one symbol.';
    }
    return $raw;
}

/** Customers must be 13 or older to hold an account. */
function v_birthdate(string $raw, array &$errors, string $field = 'birth_date'): ?string
{
    $val = trim($raw);
    if ($val === '') {
        return null;                       // optional field
    }
    $d = DateTime::createFromFormat('Y-m-d', $val);
    if (!$d || $d->format('Y-m-d') !== $val) {
        $errors[$field] = 'Enter the date as YYYY-MM-DD.';
        return null;
    }
    $age = (int) $d->diff(new DateTime('today'))->y;
    if ($age < 13) {
        $errors[$field] = 'Account holders must be at least 13 years old.';
    } elseif ($age > 120) {
        $errors[$field] = 'Please check the year of birth.';
    }
    return $val;
}

/* ------------------------------------------------------------------ */
/*  Pricing                                                            */
/* ------------------------------------------------------------------ */

/** Weekend screenings carry a surcharge on top of the stored base price. */
function showtime_price(array $showtime): float
{
    $price = (float) $showtime['base_price'];
    $dow   = (int) date('N', strtotime($showtime['show_date']));   // 6 = Sat
    if ($dow >= 6) {
        $price += WEEKEND_SURCHARGE;
    }
    return round($price, 2);
}

/** Price of one specific seat at one specific screening. */
function seat_price(array $showtime, array $seat): float
{
    return round(showtime_price($showtime) + (float) $seat['surcharge'], 2);
}

/* ------------------------------------------------------------------ */
/*  Screenings and seats                                               */
/* ------------------------------------------------------------------ */

/** Full detail for one screening, or null if it does not exist. */
function get_showtime(int $showtime_id): ?array
{
    return db_one(
        'SELECT st.*, m.title, m.poster, m.banner, m.classification, m.duration_min,
                m.genre, m.language, m.synopsis,
                h.hall_name, h.hall_type, h.row_count, h.seats_per_row,
                c.cinema_id, c.name AS cinema_name, c.location AS cinema_location,
                c.address AS cinema_address
           FROM showtimes st
           JOIN movies  m ON m.movie_id  = st.movie_id
           JOIN halls   h ON h.hall_id   = st.hall_id
           JOIN cinemas c ON c.cinema_id = h.cinema_id
          WHERE st.showtime_id = ?',
        [$showtime_id]
    );
}

/** Has this screening already started? */
function showtime_has_passed(array $showtime): bool
{
    return strtotime($showtime['show_date'] . ' ' . $showtime['show_time']) < time();
}

/** Every seat in the hall, in row / number order. */
function get_hall_seats(int $hall_id): array
{
    return db_all(
        'SELECT seat_id, hall_id, seat_row, seat_number, seat_type, surcharge
           FROM seats
          WHERE hall_id = ?
          ORDER BY seat_row, seat_number',
        [$hall_id]
    );
}

/**
 * seat_id => booking_ref for every seat already sold at this screening.
 * Only confirmed bookings hold a seat; cancelling releases it.
 */
function get_taken_seat_ids(int $showtime_id): array
{
    $rows = db_all(
        'SELECT bs.seat_id, b.booking_ref
           FROM booking_seats bs
           JOIN bookings b ON b.booking_id = bs.booking_id
          WHERE bs.showtime_id = ?
            AND b.booking_status = ?',
        [$showtime_id, 'confirmed']
    );

    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['seat_id']] = $r['booking_ref'];
    }
    return $out;
}

/** Seats the signed-in customer is already holding on their short-list. */
function get_reserved_seat_ids(int $showtime_id, int $user_id): array
{
    $rows = db_all(
        'SELECT ps.seat_id
           FROM preference_seats ps
           JOIN preferences p ON p.pref_id = ps.pref_id
          WHERE p.showtime_id = ? AND p.user_id = ?',
        [$showtime_id, $user_id]
    );

    return array_map(static fn($r) => (int) $r['seat_id'], $rows);
}

/** How many seats are still free at a screening. */
function seats_remaining(int $showtime_id, int $hall_id): int
{
    $total = (int) (db_one(
        'SELECT (row_count * seats_per_row) AS n FROM halls WHERE hall_id = ?',
        [$hall_id]
    )['n'] ?? 0);

    return max(0, $total - count(get_taken_seat_ids($showtime_id)));
}

/** "Selling fast" / "Plenty left" badge wording for the schedule table. */
function availability_label(int $remaining, int $total): array
{
    if ($remaining === 0) {
        return ['Sold out', 'sold'];
    }
    $pct = $total > 0 ? ($remaining / $total) * 100 : 0;
    if ($pct <= 15) {
        return ['Last few seats', 'low'];
    }
    if ($pct <= 45) {
        return ['Selling fast', 'mid'];
    }
    return ['Good availability', 'good'];
}

/* ------------------------------------------------------------------ */
/*  Short-list (preferences)                                           */
/* ------------------------------------------------------------------ */

/** Number of entries the customer currently holds. */
function preference_count(int $user_id): int
{
    return (int) (db_one(
        'SELECT COUNT(*) AS n FROM preferences WHERE user_id = ?',
        [$user_id]
    )['n'] ?? 0);
}

/**
 * The customer's short-list in preference order, each entry carrying its
 * screening details, its seats, and whether those seats are all still free.
 */
function get_preferences(int $user_id): array
{
    $prefs = db_all(
        'SELECT p.*, st.show_date, st.show_time, st.format, st.base_price, st.status,
                st.hall_id, m.movie_id, m.title, m.poster, m.classification,
                m.duration_min, h.hall_name, h.hall_type,
                c.name AS cinema_name, c.location AS cinema_location
           FROM preferences p
           JOIN showtimes st ON st.showtime_id = p.showtime_id
           JOIN movies    m  ON m.movie_id     = st.movie_id
           JOIN halls     h  ON h.hall_id      = st.hall_id
           JOIN cinemas   c  ON c.cinema_id    = h.cinema_id
          WHERE p.user_id = ?
          ORDER BY p.rank_order, p.pref_id',
        [$user_id]
    );

    foreach ($prefs as &$p) {
        $p['seats'] = db_all(
            'SELECT ps.seat_id, ps.seat_price, s.seat_row, s.seat_number, s.seat_type
               FROM preference_seats ps
               JOIN seats s ON s.seat_id = ps.seat_id
              WHERE ps.pref_id = ?
              ORDER BY s.seat_row, s.seat_number',
            [(int) $p['pref_id']]
        );

        $taken = get_taken_seat_ids((int) $p['showtime_id']);
        $clash = [];
        foreach ($p['seats'] as $s) {
            if (isset($taken[(int) $s['seat_id']])) {
                $clash[] = $s['seat_row'] . $s['seat_number'];
            }
        }

        $p['clashes']   = $clash;
        $p['expired']   = showtime_has_passed($p);
        $p['cancelled'] = ($p['status'] === 'cancelled');
        $p['available'] = empty($clash) && !$p['expired'] && !$p['cancelled'];
    }
    unset($p);

    return $prefs;
}

/* ------------------------------------------------------------------ */
/*  Bookings                                                           */
/* ------------------------------------------------------------------ */

/** CS-prefixed reference that is guaranteed unique in the bookings table. */
function make_booking_ref(): string
{
    do {
        $ref = 'CS' . strtoupper(bin2hex(random_bytes(3))) . date('y');
        $hit = db_one('SELECT booking_id FROM bookings WHERE booking_ref = ?', [$ref]);
    } while ($hit);

    return $ref;
}

/** One booking with its screening, or null. */
function get_booking(int $booking_id): ?array
{
    return db_one(
        'SELECT b.*, st.show_date, st.show_time, st.format, st.showtime_id,
                m.title, m.poster, m.classification, m.duration_min,
                h.hall_name, h.hall_type,
                c.name AS cinema_name, c.location AS cinema_location,
                c.address AS cinema_address,
                u.full_name, u.email
           FROM bookings   b
           JOIN showtimes st ON st.showtime_id = b.showtime_id
           JOIN movies    m  ON m.movie_id     = st.movie_id
           JOIN halls     h  ON h.hall_id      = st.hall_id
           JOIN cinemas   c  ON c.cinema_id    = h.cinema_id
           JOIN users     u  ON u.user_id      = b.user_id
          WHERE b.booking_id = ?',
        [$booking_id]
    );
}

/** The seats attached to one booking. */
function archive_booking_seats(int $booking_id): void
{
    db_execute(
        'INSERT IGNORE INTO booking_seat_history
            (booking_id, seat_id, seat_row, seat_number, seat_type, seat_price)
         SELECT bs.booking_id, bs.seat_id, s.seat_row, s.seat_number, s.seat_type, bs.seat_price
           FROM booking_seats bs JOIN seats s ON s.seat_id = bs.seat_id
          WHERE bs.booking_id = ?', [$booking_id]
    );
}

function get_booking_seats(int $booking_id): array
{
    $history = db_all('SELECT seat_id, seat_row, seat_number, seat_type, seat_price
                        FROM booking_seat_history WHERE booking_id = ? ORDER BY seat_row, seat_number',
                      [$booking_id]);
    if ($history) { return $history; }
    return db_all(
        'SELECT bs.seat_id, bs.seat_price, s.seat_row, s.seat_number, s.seat_type
           FROM booking_seats bs
           JOIN seats s ON s.seat_id = bs.seat_id
          WHERE bs.booking_id = ?
          ORDER BY s.seat_row, s.seat_number',
        [$booking_id]
    );
}

/** "A5, A6, A7" */
function seat_list_text(array $seats): string
{
    return implode(', ', array_map(
        static fn($s) => $s['seat_row'] . $s['seat_number'],
        $seats
    ));
}

/* ------------------------------------------------------------------ */
/*  Misc                                                               */
/* ------------------------------------------------------------------ */

/** Marks the current page in the navigation bar. */
function nav_active(string $file): string
{
    return basename($_SERVER['SCRIPT_NAME']) === $file ? ' class="active"' : '';
}

/** The list of distinct genres, for the filter on movies.php. */
function all_genres(): array
{
    $rows  = db_all("SELECT DISTINCT genre FROM movies WHERE status <> 'archived'");
    $split = [];
    foreach ($rows as $r) {
        foreach (explode(',', $r['genre']) as $g) {
            $g = trim($g);
            if ($g !== '') {
                $split[$g] = true;
            }
        }
    }
    ksort($split);
    return array_keys($split);
}
