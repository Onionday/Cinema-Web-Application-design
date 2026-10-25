<?php
/* =====================================================================
 * includes/rolling_schedule.php
 *
 * Keeps the schedule "always this week" without anyone re-importing
 * the seed data or adding screenings by hand.
 *
 * On each request it asks one cheap question: does every day from today
 * to six days ahead have at least one screening? If so, nothing else
 * happens. Any day with no screenings at all is filled by copying the
 * same weekday from the most recent week that has one (Monday copies the
 * last Monday, and so on). A day staff have scheduled anything on is
 * left exactly as they planned it.
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

/** The dates between $from and $to (inclusive) that have no screenings at all. */
function unscheduled_days(string $from, string $to): array
{
    $have = [];
    foreach (db_all('SELECT DISTINCT show_date FROM showtimes WHERE show_date BETWEEN ? AND ?',
                    [$from, $to]) as $r) {
        $have[(string) $r['show_date']] = true;
    }
    $missing = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if (!isset($have[$d])) {
            $missing[] = $d;
        }
    }
    return $missing;
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

    /* The usual case: every day of the coming week already has screenings. */
    if (!unscheduled_days($today, $target)) {
        return 0;
    }

    /* Only one request at a time may extend the schedule. */
    $lock = db_one("SELECT GET_LOCK('cinesphere_rolling_schedule', 5) AS ok");
    if (!$lock || (int) $lock['ok'] !== 1) {
        return 0;
    }

    $added = 0;
    try {
        /* Another request may have filled some days while we waited. A day
           staff have put anything on (even one screening) is left as planned. */
        foreach (unscheduled_days($today, $target) as $d) {

            /* Copy the same weekday from the most recent week that has one,
               looking back up to eight weeks. */
            $template = [];
            for ($weeksBack = 1; $weeksBack <= 8 && !$template; $weeksBack++) {
                $from = date('Y-m-d', strtotime($d . ' -' . (7 * $weeksBack) . ' days'));
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
