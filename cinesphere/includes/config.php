<?php









define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', getenv('CINESPHERE_DB_NAME') ?: 'cinesphere');
define('DB_PORT', 3306);


define('SITE_NAME',    'CineSphere');
define('SITE_TAGLINE', 'Best cinema in town.');
define('SITE_EMAIL',   'bookings@cinesphere.local');


define('MAX_SEATS_PER_BOOKING', 8);
define('MAX_PREFERENCES',       6);
define('WEEKEND_SURCHARGE',  2.00);
define('BOOKING_FEE',        0.80);
define('CURRENCY',           'S$');








define('MAIL_LOCAL_ACCOUNT', 'coursework@cinesphere.local');





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


if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
