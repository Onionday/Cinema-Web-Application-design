<?php


























require_once __DIR__ . '/scheduling.php';

if (!defined('ROLLING_SCHEDULE')) {
    define('ROLLING_SCHEDULE', true);
}
if (!defined('SCHEDULE_DAYS_AHEAD')) {
    define('SCHEDULE_DAYS_AHEAD', 7);
}


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






function ensure_rolling_schedule(): int
{
    static $done = false;
    if ($done || !ROLLING_SCHEDULE) {
        return 0;
    }
    $done = true;

    $today  = date('Y-m-d');
    $target = date('Y-m-d', strtotime('+' . (SCHEDULE_DAYS_AHEAD - 1) . ' days'));


    if (!unscheduled_days($today, $target)) {
        return 0;
    }


    $lock = db_one("SELECT GET_LOCK('cinesphere_rolling_schedule', 5) AS ok");
    if (!$lock || (int) $lock['ok'] !== 1) {
        return 0;
    }

    $added = 0;
    try {


        foreach (unscheduled_days($today, $target) as $d) {



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
