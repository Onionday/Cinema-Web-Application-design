<?php














require_once __DIR__ . '/includes/auth.php';

require_post();

$showtime_id = (int) ($_POST['showtime_id'] ?? 0);
$rawSeats = is_string($_POST['seat_ids'] ?? null) ? $_POST['seat_ids'] : '';

if ($rawSeats === '' && is_array($_POST['seats'] ?? null)) {
    $rawSeats = implode(',', array_filter($_POST['seats'], 'is_string'));
}


$seat_ids = [];
foreach (explode(',', $rawSeats) as $piece) {
    $piece = trim($piece);
    if ($piece !== '' && ctype_digit($piece)) {
        $seat_ids[(int) $piece] = true;
    }
}
$seat_ids = array_keys($seat_ids);

$back = 'booking.php?showtime=' . $showtime_id;


if ($showtime_id <= 0) {
    flash('error', 'No screening was supplied.');
    redirect('showtimes.php');
}

if (!$seat_ids) {
    flash('error', 'Please choose at least one seat before adding to your short-list.');
    redirect($back);
}

if (count($seat_ids) > MAX_SEATS_PER_BOOKING) {
    flash('error', 'You can book at most ' . MAX_SEATS_PER_BOOKING
                 . ' seats for a single screening.');
    redirect($back);
}


if (!is_logged_in()) {
    $_SESSION['pending_seats'] = [
        'showtime_id' => $showtime_id,
        'seat_ids'    => $seat_ids,
    ];
    $_SESSION['redirect_after_login'] = $back;
    flash('info', 'Your selection is saved while you sign in. Seats are secured only after confirmation.');
    redirect('account.php');
}

$user_id = current_user_id();


$showtime = get_showtime($showtime_id);

if (!$showtime) {
    flash('error', 'That screening no longer exists.');
    redirect('showtimes.php');
}
if ($showtime['status'] === 'cancelled') {
    flash('error', 'That screening has been cancelled.');
    redirect('movie.php?id=' . (int) $showtime['movie_id']);
}
if (showtime_has_passed($showtime)) {
    flash('error', 'That screening has already started.');
    redirect('movie.php?id=' . (int) $showtime['movie_id']);
}


if (preference_count($user_id) >= MAX_PREFERENCES) {
    flash('warning', 'Your short-list already holds ' . MAX_PREFERENCES
                   . ' entries, which is the maximum. Remove one first.');
    redirect('preferences.php');
}


$placeholders = implode(',', array_fill(0, count($seat_ids), '?'));

$validSeats = db_all(
    "SELECT seat_id, seat_row, seat_number, seat_type, surcharge
       FROM seats
      WHERE hall_id = ? AND seat_id IN ($placeholders)
      ORDER BY seat_row, seat_number",
    array_merge([(int) $showtime['hall_id']], $seat_ids)
);

if (count($validSeats) !== count($seat_ids)) {
    flash('error', 'One of those seats is not in this hall. Please choose again.');
    redirect($back);
}


$taken  = get_taken_seat_ids($showtime_id);
$clash  = [];
foreach ($validSeats as $s) {
    if (isset($taken[(int) $s['seat_id']])) {
        $clash[] = $s['seat_row'] . $s['seat_number'];
    }
}

if ($clash) {
    flash('error', 'Someone booked ' . implode(', ', $clash)
                 . ' while you were choosing. Those seats have been released '
                 . 'from your selection - please pick again.');
    redirect($back);
}


$alreadyHeld = get_reserved_seat_ids($showtime_id, $user_id);
$dupes = [];
foreach ($validSeats as $s) {
    if (in_array((int) $s['seat_id'], $alreadyHeld, true)) {
        $dupes[] = $s['seat_row'] . $s['seat_number'];
    }
}

if ($dupes) {
    flash('warning', 'You have already short-listed ' . implode(', ', $dupes)
                   . ' for this screening on your short-list.');
    redirect('preferences.php');
}


$conn = db();
$conn->begin_transaction();

try {

    $live = db_one('SELECT * FROM showtimes WHERE showtime_id = ? FOR UPDATE', [$showtime_id]);
    if (!$live || $live['status'] !== 'open' || showtime_has_passed($live)) {
        throw new RuntimeException('This screening is no longer available.');
    }
    $showtime = array_replace($showtime, $live);
    $prices = [];
    $subtotal = 0.0;
    foreach ($validSeats as $s) {
        $prices[(int)$s['seat_id']] = seat_price($showtime, $s);
        $subtotal += $prices[(int)$s['seat_id']];
    }
    $estTotal = round($subtotal + BOOKING_FEE * count($validSeats), 2);
    $nextRank = 1 + (int) (db_one(
        'SELECT COALESCE(MAX(rank_order), 0) AS r FROM preferences WHERE user_id = ?',
        [$user_id]
    )['r'] ?? 0);

    $note = sprintf('%s, %s at %s',
                    $showtime['hall_name'],
                    pretty_date($showtime['show_date']),
                    pretty_time($showtime['show_time']));


    $res = db_execute(
        'INSERT INTO preferences
                (user_id, showtime_id, rank_order, seat_count, est_total, note)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$user_id, $showtime_id, $nextRank, count($validSeats), $estTotal, $note],
        'iiiids'
    );

    $pref_id = (int) $res['insert_id'];


    foreach ($validSeats as $s) {
        db_execute(
            'INSERT INTO preference_seats (pref_id, seat_id, seat_price) VALUES (?, ?, ?)',
            [$pref_id, (int) $s['seat_id'], $prices[(int) $s['seat_id']]],
            'iid'
        );
    }

    $conn->commit();

} catch (Throwable $ex) {
    $conn->rollback();
    flash('error', 'We could not add that to your short-list. Please try again.'
                 . (DEBUG ? ' (' . $ex->getMessage() . ')' : ''));
    redirect($back);
}


$names = implode(', ', array_map(
    static fn($s) => $s['seat_row'] . $s['seat_number'],
    $validSeats
));

flash('success', 'Added to your short-list as choice #' . $nextRank . ': '
                . $showtime['title'] . ', seats ' . $names . '.');

redirect('preferences.php');
