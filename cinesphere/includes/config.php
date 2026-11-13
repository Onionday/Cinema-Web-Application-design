<?php
/* =====================================================================
 * CineSphere - Cinema Ticket Booking Portal
 * includes/config.php
 *
 * Every page begins by requiring this file. It starts the session,
 * defines the site-wide constants and sets up error reporting.
 * ===================================================================== */

/* ---------- Database credentials (XAMPP defaults) ----------------- */
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');            // XAMPP's MySQL root password is blank
define('DB_NAME', getenv('CINESPHERE_DB_NAME') ?: 'cinesphere');
define('DB_PORT', 3306);

/* ---------- Site identity ----------------------------------------- */
define('SITE_NAME',    'CineSphere');
define('SITE_TAGLINE', 'Best cinema in town.');
define('SITE_EMAIL',   'bookings@cinesphere.local');

/* ---------- Business rules ---------------------------------------- */
define('MAX_SEATS_PER_BOOKING', 8);   // per screening
define('MAX_PREFERENCES',       6);   // entries on the short-list
define('WEEKEND_SURCHARGE',  2.00);   // added on Sat / Sun screenings
define('BOOKING_FEE',        0.80);   // per ticket
define('CURRENCY',           'S$');

/* ---------- Local coursework mail ----------------------------------
 * PHP mail() delivers to the local SMTP server. All messages go to the
 * single coursework mailbox, never to a customer's external address.
 * Windows php.ini: SMTP=127.0.0.1, smtp_port=1025, sendmail_from below.
 * On Linux/XAMPP configure a local-only sendmail transport instead.
 * See README.md for the Mailpit inbox and verification steps.
 * ------------------------------------------------------------------ */
define('MAIL_LOCAL_ACCOUNT', 'coursework@cinesphere.local');

/* ---------- Error reporting --------------------------------------
 * Development: show everything. Set DEBUG to false before submission
 * if you would rather errors were only logged.
 * ------------------------------------------------------------------ */
define('DEBUG', true);
if (DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

date_default_timezone_set('Asia/Singapore');

/* ---------- Session ----------------------------------------------- */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
