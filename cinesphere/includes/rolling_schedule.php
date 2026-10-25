<?php
/* =====================================================================
 * includes/rolling_schedule.php
 *
 * Keeps the schedule "always this week" without anyone re-importing
 * the seed data or adding screenings by hand.
 *
 * On each request it asks one cheap question: does the showtimes table
 * reach six days from today? If it does, nothing else happens. If not,
 * the missing days are filled by copying the same weekday from the most
 * recent week on record (Monday copies the last Monday, and so on).
 *
 *   - Past screenings are never changed or deleted, so booking history
 *     and e-tickets keep pointing at the screening they were sold for.
 *   - Cancelled screenings and films that are no longer "now showing"
 *     are not copied forward.
 *   - Every copied screening goes through the same hall clash check as
 *     the staff scheduling form (runtime + 20-minute turnaround), and the
 *     UNIQUE (hall, date, time) key stops duplicates.
 *   - A named MySQL lock makes sure two visitors arriving at the same
 *     moment cannot both generate the same day.
 *
 * SQL used: SELECT, INSERT.
 * ===================================================================== */

require_once __DIR__ . '/scheduling.php';

if (!defined('ROLLING_SCHEDULE')) {
    define('ROLLING_SCHEDULE', true);  // set false to manage the schedule by hand
}
if (!defined('SCHEDULE_DAYS_AHEAD')) {
    define('SCHEDULE_DAYS_AHEAD', 7);   // today plus the next six days
}

/** Days between two Y-m-d dates (b - a). */
function days_between(string $a, string $b): int
{
    return (int) round((strtotime($b . ' 12:00') - strtotime($a . ' 12:00')) / 86400);
}

/** Last date on the schedule, or null when there are no screenings. */
function last_scheduled_date(): ?string
{
    $row = db_one('SELECT MAX(show_date) AS last_day FROM showtimes');
    return $row && $row['last_day'] ? (string) $row['last_day'] : null;
}

/**
 * Makes sure screenings exist from today up to SCHEDULE_DAYS_AHEAD - 1
 * days ahead. Returns the number of screenings it added (0 most of the
 * time). Runs at most once per request.
 */
function ensure_rolling_schedule(): int
{
    static $done = false;
    if ($done || !ROLLING_SCHEDULE) {
        return 0;
    }
    $done = true;

    $today  = date('Y-m-d');
    $target = date('Y-m-d', strtotime('+' . (SCHEDULE_DAYS_AHEAD - 1) . ' days'));

    $last = last_scheduled_date();
    if ($last === null || $last >= $target) {
        return 0;   // empty database (import the seed) or already up to date
    }

    /* Only one request at a time may extend the schedule. */
    $lock = db_one("SELECT GET_LOCK('cinesphere_rolling_schedule', 5) AS ok");
    if (!$lock || (int) $lock['ok'] !== 1) {
        return 0;
    }

    $added = 0;
    try {
        /* Another request may have finished the job while we waited. */
        $last = last_scheduled_date();
        if ($last === null || $last >= $target) {
            return 0;
        }

        /* Days already in the past are skipped: nobody can book them. */
        $first = date('Y-m-d', max(strtotime($today), strtotime($last . ' +1 day')));

        for ($d = $first; $d <= $target; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {

            /* Same weekday in the last complete week on record. */
            $gap    = days_between($last, $d);                 // at least 1
            $source = date('Y-m-d', strtotime($d . ' -' . (7 * (int) ceil($gap / 7)) . ' days'));

            /* If that weekday had nothing to copy (e.g. staff cancelled every
               screening), look one week further back, up to four weeks. */
            $template = [];
            for ($weeksBack = 0; $weeksBack < 4 && !$template; $weeksBack++) {
                $from = date('Y-m-d', strtotime($source . ' -' . (7 * $weeksBack) . ' days'));
                $template = db_all(
                    "SELECT st.movie_id, st.hall_id, st.show_time, st.format,
                            st.base_price, m.duration_min
                       FROM showtimes st
                       JOIN movies m ON m.movie_id = st.movie_id
                      WHERE st.show_date = ?
                        AND st.status <> 'cancelled'
                        AND m.status = 'now_showing'
                      ORDER BY st.hall_id, st.show_time",
                    [$from]
                );
            }

            foreach ($template as $t) {
                /* Same clash rule as admin_action.php: runtime + 20 min. */
                if (screening_conflict((int) $t['hall_id'], $d, (string) $t['show_time'],
                                       (int) $t['duration_min'])) {
                    continue;
                }
                $res = db_execute(
                    "INSERT IGNORE INTO showtimes
                            (movie_id, hall_id, show_date, show_time, format, base_price, status)
                     VALUES (?, ?, ?, ?, ?, ?, 'open')",
                    [(int) $t['movie_id'], (int) $t['hall_id'], $d, (string) $t['show_time'],
                     (string) $t['format'], (float) $t['base_price']],
                    'iisssd'
                );
                $added += $res['affected'] > 0 ? 1 : 0;
            }
        }
    } finally {
        db_query("SELECT RELEASE_LOCK('cinesphere_rolling_schedule')");
    }

    return $added;
}
