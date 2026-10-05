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
define('DB_NAME', 'cinesphere');
define('DB_PORT', 3306);

/* ---------- Site identity ----------------------------------------- */
define('SITE_NAME',    'CineSphere');
define('SITE_TAGLINE', 'Book the seat you actually want.');
define('SITE_EMAIL',   'bookings@cinesphere.local');

/* ---------- Business rules ---------------------------------------- */
define('MAX_SEATS_PER_BOOKING', 8);   // per screening
define('MAX_PREFERENCES',       6);   // entries on the short-list
define('WEEKEND_SURCHARGE',  2.00);   // added on Sat / Sun screenings
define('BOOKING_FEE',        0.80);   // per ticket
define('CURRENCY',           'S$');

/* ---------- Mail -----------------------------------------------------
 * 'file' writes each acknowledgement into includes/../mail/ as an .eml
 *        file. This is the default, and is what the project guidelines
 *        ask for - nothing leaves the local web server.
 * 'mail' calls PHP's mail() instead (needs a configured MTA / sendmail).
 * ------------------------------------------------------------------ */
define('MAIL_MODE', 'file');
define('MAIL_DIR',  dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mail');

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
