<?php
/* =====================================================================
 * admin_action.php
 *
 * Every write the staff page can make, behind one gate: POST only, CSRF
 * checked, and the session must belong to an account whose role is
 * 'admin'.
 *
 * SQL used: SELECT, INSERT, UPDATE.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';

require_post();
require_admin();

$action = (string) ($_POST['action'] ?? '');

switch ($action) {

    /* ============================================================== */
    /*  Schedule a new screening                                      */
    /* ============================================================== */
    case 'add_showtime':

        $errors    = [];
        $movie_id  = (int) ($_POST['movie_id'] ?? 0);
        $hall_id   = (int) ($_POST['hall_id'] ?? 0);
        $show_date = trim((string) ($_POST['show_date'] ?? ''));
        $show_time = trim((string) ($_POST['show_time'] ?? ''));
        $format    = trim((string) ($_POST['format'] ?? ''));
        $price     = (float) ($_POST['base_price'] ?? 0);

        /* -- film -- */
        $movie = $movie_id > 0
            ? db_one('SELECT movie_id, title, duration_min FROM movies WHERE movie_id = ?', [$movie_id])
            : null;
        if (!$movie) {
            $errors['movie_id'] = 'Choose a film from the list.';
        }

        /* -- hall -- */
        $hall = $hall_id > 0
            ? db_one('SELECT hall_id, hall_name FROM halls WHERE hall_id = ?', [$hall_id])
            : null;
        if (!$hall) {
            $errors['hall_id'] = 'Choose a hall from the list.';
        }

        /* -- date -- */
        $d = DateTime::createFromFormat('Y-m-d', $show_date);
        if (!$d || $d->format('Y-m-d') !== $show_date) {
            $errors['show_date'] = 'Enter the date as YYYY-MM-DD.';
        } elseif ($show_date < date('Y-m-d')) {
            $errors['show_date'] = 'A screening cannot be scheduled in the past.';
        } elseif ($show_date > date('Y-m-d', strtotime('+90 days'))) {
            $errors['show_date'] = 'The schedule only runs 90 days ahead.';
        }

        /* -- time -- */
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $show_time)) {
            $errors['show_time'] = 'Enter the start time as HH:MM.';
        } else {
            if (strlen($show_time) === 5) {
                $show_time .= ':00';
            }
            /* Nothing may start between 03:00 and 09:59 - the halls are closed. */
            $hour = (int) substr($show_time, 0, 2);
            if ($hour >= 3 && $hour < 10) {
                $errors['show_time'] = 'The venues are closed between 03:00 and 10:00.';
            }
        }

        /* -- not in the past, if the date is today -- */
        if (!$errors && strtotime($show_date . ' ' . $show_time) < time()) {
            $errors['show_time'] = 'That time has already passed today.';
        }

        /* -- format -- */
        if (!in_array($format, ['2D', '3D', 'IMAX'], true)) {
            $errors['format'] = 'Choose 2D, 3D or IMAX.';
        }

        /* -- price -- */
        if ($price < 5 || $price > 60) {
            $errors['base_price'] = 'The base price must be between '
                                  . money(5) . ' and ' . money(60) . '.';
        }

        /* -- the hall must be free: nothing may start within the runtime
              of this film plus 20 minutes of turnaround, either side -- */
        if (!$errors) {
            $runtime = (int) $movie['duration_min'] + 20;
            $startTs = strtotime($show_date . ' ' . $show_time);

            $sameDay = db_all(
                'SELECT st.show_time, m.title, m.duration_min
                   FROM showtimes st JOIN movies m ON m.movie_id = st.movie_id
                  WHERE st.hall_id = ? AND st.show_date = ? AND st.status <> ?',
                [$hall_id, $show_date, 'cancelled']
            );

            foreach ($sameDay as $other) {
                $oStart = strtotime($show_date . ' ' . $other['show_time']);
                $oEnd   = $oStart + ((int) $other['duration_min'] + 20) * 60;
                $myEnd  = $startTs + $runtime * 60;

                if ($startTs < $oEnd && $myEnd > $oStart) {
                    $errors['show_time'] = $hall['hall_name'] . ' is busy then - '
                        . $other['title'] . ' runs from '
                        . pretty_time($other['show_time']) . '.';
                    break;
                }
            }
        }

        if ($errors) {
            stash_form('showtime', $errors, [
                'movie_id'   => $movie_id,
                'hall_id'    => $hall_id,
                'show_date'  => $show_date,
                'show_time'  => substr($show_time, 0, 5),
                'format'     => $format,
                'base_price' => $price,
            ]);
            flash('error', 'The screening was not added: ' . reset($errors));
            redirect('admin.php');
        }

        /* INSERT */
        try {
            db_execute(
                'INSERT INTO showtimes (movie_id, hall_id, show_date, show_time, format, base_price)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$movie_id, $hall_id, $show_date, $show_time, $format, $price],
                'iisssd'
            );
        } catch (Throwable $ex) {
            flash('error', 'That hall already has a screening at exactly that time.');
            redirect('admin.php');
        }

        flash('success', $movie['title'] . ' scheduled in ' . $hall['hall_name']
                       . ' on ' . pretty_date($show_date) . ' at '
                       . pretty_time($show_time) . '.');
        redirect('admin.php');


    /* ============================================================== */
    /*  Move a film between "now showing" and "coming soon"           */
    /* ============================================================== */
    case 'set_movie_status':

        $movie_id = (int) ($_POST['movie_id'] ?? 0);
        $status   = (string) ($_POST['status'] ?? '');

        if (!in_array($status, ['now_showing', 'coming_soon', 'archived'], true)) {
            flash('error', 'Unknown catalogue status.');
            redirect('admin.php');
        }

        $movie = db_one('SELECT title FROM movies WHERE movie_id = ?', [$movie_id]);
        if (!$movie) {
            flash('error', 'That film is not in the catalogue.');
            redirect('admin.php');
        }

        /* UPDATE */
        db_execute('UPDATE movies SET status = ? WHERE movie_id = ?',
                   [$status, $movie_id]);

        $words = [
            'now_showing' => 'is now on release',
            'coming_soon' => 'has been moved to coming soon',
            'archived'    => 'has been archived',
        ];

        flash('success', $movie['title'] . ' ' . $words[$status] . '.');
        redirect('admin.php');


    /* ============================================================== */
    /*  Cancel a screening                                            */
    /* ============================================================== */
    case 'cancel_showtime':

        $showtime_id = (int) ($_POST['showtime_id'] ?? 0);
        $st = get_showtime($showtime_id);

        if (!$st) {
            flash('error', 'That screening does not exist.');
            redirect('admin.php');
        }

        $affected = (int) (db_one(
            "SELECT COUNT(*) AS n FROM bookings
              WHERE showtime_id = ? AND booking_status = 'confirmed'",
            [$showtime_id]
        )['n'] ?? 0);

        /* UPDATE - the screening is closed ... */
        db_execute("UPDATE showtimes SET status = 'cancelled' WHERE showtime_id = ?",
                   [$showtime_id]);

        /* ... and every booking on it is cancelled and refunded. */
        if ($affected > 0) {
            db_execute(
                "UPDATE bookings
                    SET booking_status = 'cancelled', payment_status = 'refunded'
                  WHERE showtime_id = ? AND booking_status = 'confirmed'",
                [$showtime_id]
            );

            db_execute('DELETE FROM booking_seats WHERE showtime_id = ?', [$showtime_id]);
        }

        flash('success', $st['title'] . ' on ' . pretty_date($st['show_date'])
                       . ' at ' . pretty_time($st['show_time']) . ' has been cancelled'
                       . ($affected > 0
                          ? ", and {$affected} booking" . ($affected === 1 ? '' : 's')
                            . ' refunded.'
                          : '. No seats had been sold.'));
        redirect('admin.php');


    /* ============================================================== */
    /*  Reopen a cancelled screening                                  */
    /* ============================================================== */
    case 'reopen_showtime':

        $showtime_id = (int) ($_POST['showtime_id'] ?? 0);
        $st = get_showtime($showtime_id);

        if (!$st) {
            flash('error', 'That screening does not exist.');
            redirect('admin.php');
        }

        if (showtime_has_passed($st)) {
            flash('error', 'That screening is in the past and cannot be reopened.');
            redirect('admin.php');
        }

        /* UPDATE */
        db_execute("UPDATE showtimes SET status = 'open' WHERE showtime_id = ?",
                   [$showtime_id]);

        flash('success', $st['title'] . ' on ' . pretty_date($st['show_date'])
                       . ' is on sale again.');
        redirect('admin.php');


    /* ============================================================== */
    /*  Triage a feedback message                                     */
    /* ============================================================== */
    case 'set_msg_status':

        $msg_id = (int) ($_POST['msg_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');

        if (!in_array($status, ['new', 'read', 'resolved'], true)) {
            flash('error', 'Unknown message status.');
            redirect('admin.php');
        }

        $msg = db_one('SELECT name FROM contact_messages WHERE msg_id = ?', [$msg_id]);
        if (!$msg) {
            flash('error', 'That message no longer exists.');
            redirect('admin.php');
        }

        /* UPDATE */
        db_execute('UPDATE contact_messages SET msg_status = ? WHERE msg_id = ?',
                   [$status, $msg_id]);

        flash('success', 'Message #CM' . $msg_id . ' from ' . $msg['name']
                       . ' marked ' . $status . '.');
        redirect('admin.php?msgs=' . urlencode($status));


    /* ============================================================== */
    default:
        flash('error', 'Unknown staff action.');
        redirect('admin.php');
}
