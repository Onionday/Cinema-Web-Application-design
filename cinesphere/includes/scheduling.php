<?php
require_once __DIR__ . '/functions.php';

/** Serialize schedule changes for a hall. Must be inside a transaction. */
function lock_hall(int $hallId): void
{
    if (!db_one('SELECT hall_id FROM halls WHERE hall_id = ? FOR UPDATE', [$hallId])) {
        throw new RuntimeException('That hall no longer exists.');
    }
}

/** Compare full date-time intervals, including the 20-minute turnaround. */
function screening_conflict(int $hallId, string $date, string $time, int $duration, int $exclude = 0): ?array
{
    $start = $date . ' ' . $time;
    $end = date('Y-m-d H:i:s', strtotime($start) + ($duration + 20) * 60);
    return db_one(
        "SELECT st.showtime_id, st.show_date, st.show_time, m.title
           FROM showtimes st JOIN movies m ON m.movie_id = st.movie_id
          WHERE st.hall_id = ? AND st.status <> 'cancelled' AND st.showtime_id <> ?
            AND TIMESTAMP(st.show_date, st.show_time) < ?
            AND TIMESTAMPADD(MINUTE, m.duration_min + 20,
                            TIMESTAMP(st.show_date, st.show_time)) > ?
          ORDER BY st.show_date, st.show_time LIMIT 1 FOR UPDATE",
        [$hallId, $exclude, $end, $start]
    );
}

function conflict_message(array $other): string
{
    return 'The hall is busy with ' . $other['title'] . ' on '
         . pretty_date($other['show_date']) . ' at ' . pretty_time($other['show_time'])
         . '. Allow the film runtime and 20 minutes for turnaround.';
}
