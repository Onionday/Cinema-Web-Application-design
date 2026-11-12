<?php















require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/checkout.php';

require_post();
require_login();

$user_id = current_user_id();
$me      = current_user();


$picked = $_POST['confirm'] ?? [];
if (!is_array($picked)) {
    $picked = [];
}
$picked = array_values(array_unique(array_map('intval', $picked)));

$gateway = (string) ($_POST['gateway'] ?? 'success');
$agreed  = !empty($_POST['agree']);

if (!$picked) {
    flash('error', 'Tick at least one available booking before confirming.');
    redirect('preferences.php');
}

if (!$agreed) {
    flash('error', 'Please confirm you have checked the films, times and seats.');
    redirect('preferences.php');
}

if (!in_array($gateway, ['success', 'declined'], true)) {
    $gateway = 'success';
}

$quote = take_checkout_quote($_POST['quote'] ?? null);
if ($quote === null) {
    flash('warning', 'Your checkout quote expired or is missing. Review the current prices and confirm again.');
    redirect('preferences.php');
}


$placeholders = implode(',', array_fill(0, count($picked), '?'));

$rows = db_all(
    "SELECT pref_id FROM preferences
      WHERE user_id = ? AND pref_id IN ($placeholders)
      ORDER BY rank_order, pref_id",
    array_merge([$user_id], $picked)
);

if (!$rows) {
    flash('error', 'Those short-list entries are no longer on your account.');
    redirect('preferences.php');
}

$ordered = array_map(static fn($r) => (int) $r['pref_id'], $rows);



$all = get_preferences($user_id);
$byId = [];
foreach ($all as $p) {
    $byId[(int) $p['pref_id']] = $p;
}

$toBook    = [];
$rejected  = [];

foreach ($ordered as $pid) {
    $p = $byId[$pid] ?? null;
    if (!$p) {
        continue;
    }
    if ($p['available']) {
        $toBook[] = $p;
    } else {
        $rejected[] = $p;
    }
}


foreach ($toBook as $p) {
    if (!checkout_quote_matches($quote, $p)) {
        flash('warning', 'A price or seat selection has changed. Nothing was booked. Review the current prices and confirm again.');
        redirect('preferences.php');
    }
}

if (!$toBook) {
    flash('error', 'None of the entries you selected are still available. '
                 . 'The seats were taken while you were deciding.');
    redirect('preferences.php');
}

$grandTotal = 0.0;
foreach ($toBook as $p) {
    $grandTotal += (float) $p['est_total'];
}









$authCode = strtoupper(bin2hex(random_bytes(4)));

if ($gateway === 'declined') {




    foreach ($toBook as $p) {
        db_execute(
            'INSERT INTO bookings
                    (booking_ref, user_id, showtime_id, seat_count, total_amount,
                     booking_status, payment_status, payment_method)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [make_booking_ref(), $user_id, (int) $p['showtime_id'],
             (int) $p['seat_count'], (float) $p['est_total'],
             'failed', 'unpaid', 'Gateway declined (' . $authCode . ')'],
            'siiidsss'
        );
    }

    flash('error', 'The payment gateway declined the transaction (reference '
                 . $authCode . '). Nothing has been booked and nothing has '
                 . 'been charged. Your short-list is untouched, so you can '
                 . 'try again with a different payment method.');

    redirect('preferences.php');
}




$conn      = db();
$confirmed = [];
$lost      = [];
$priceChanged = false;

foreach ($toBook as $p) {

    $conn->begin_transaction();

    try {
        $live = db_one('SELECT status, show_date, show_time FROM showtimes WHERE showtime_id = ? FOR UPDATE',
                       [(int) $p['showtime_id']]);
        if (!$live || $live['status'] !== 'open' || showtime_has_passed($live)) {
            throw new RuntimeException('This screening is no longer available.');
        }
        $choice = locked_checkout_choice((int)$p['pref_id'], $user_id);
        if (!$choice) { throw new RuntimeException('This choice has already been processed.'); }
        if (!checkout_quote_matches($quote, $choice)) {
            $priceChanged = true;
            throw new RuntimeException('The price changed during checkout. Review it and confirm again.');
        }

        $p = array_replace($p, $choice);


        $taken = get_taken_seat_ids((int) $p['showtime_id']);
        $clash = [];
        foreach ($p['seats'] as $s) {
            if (isset($taken[(int) $s['seat_id']])) {
                $clash[] = $s['seat_row'] . $s['seat_number'];
            }
        }
        if ($clash) {
            throw new RuntimeException('Seats ' . implode(', ', $clash) . ' were just taken.');
        }


        $ref = make_booking_ref();

        $res = db_execute(
            'INSERT INTO bookings
                    (booking_ref, user_id, showtime_id, seat_count, total_amount,
                     booking_status, payment_status, payment_method)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$ref, $user_id, (int) $p['showtime_id'], (int) $p['seat_count'],
             (float) $p['est_total'], 'confirmed', 'paid',
             'Third-party gateway (' . $authCode . ')'],
            'siiidsss'
        );

        $booking_id = (int) $res['insert_id'];





        foreach ($p['seats'] as $s) {
            db_execute(
                'INSERT INTO booking_seats (booking_id, showtime_id, seat_id, seat_price)
                 VALUES (?, ?, ?, ?)',
                [$booking_id, (int) $p['showtime_id'], (int) $s['seat_id'],
                 (float) $s['seat_price']],
                'iiid'
            );
        }

        archive_booking_seats($booking_id);



        db_execute('DELETE FROM preferences WHERE pref_id = ? AND user_id = ?',
                   [(int) $p['pref_id'], $user_id]);

        $conn->commit();

        $confirmed[] = $booking_id;

    } catch (Throwable $ex) {
        $conn->rollback();
        $lost[] = [
            'title'  => $p['title'],
            'reason' => $ex->getMessage(),
        ];
    }
}


$left = db_all('SELECT pref_id FROM preferences WHERE user_id = ? ORDER BY rank_order, pref_id',
               [$user_id]);
$n = 1;
foreach ($left as $r) {
    db_execute('UPDATE preferences SET rank_order = ? WHERE pref_id = ?',
               [$n++, (int) $r['pref_id']]);
}


$mailed = 0;
foreach ($confirmed as $bid) {
    $booking = get_booking($bid);
    if ($booking && send_booking_confirmation($booking, get_booking_seats($bid))) {
        $mailed++;
    }
}


if ($priceChanged) {
    flash('warning', 'A price changed during checkout. Those selections were not booked or charged. Review the current prices and confirm them again.');
}
if (!$confirmed && $priceChanged) { redirect('preferences.php'); }

if (!$confirmed) {
    flash('error', 'Payment was approved but every seat had just been sold. '
                 . 'Nothing has been charged. Please choose different seats.');
    redirect('preferences.php');
}

if ($lost) {
    $names = implode(', ', array_column($lost, 'title'));
    flash('warning', 'We could not complete ' . count($lost) . ' of your selections ('
                   . $names . ') because a screening or selection was no longer available. '
                   . 'Only the successful bookings below were charged.');
}

if ($rejected) {
    flash('info', count($rejected) . ' unavailable '
                . (count($rejected) === 1 ? 'entry was' : 'entries were')
                . ' skipped and left on your short-list.');
}

if ($mailed > 0) {
    flash('success', 'Acknowledgement '
                   . ($mailed === 1 ? 'email has' : 'emails have')
                   . ' been delivered to the local coursework inbox.');
} else {
    flash('warning', 'Your booking is confirmed, but the acknowledgement email '
                   . 'could not be sent. Your reference below is all you need '
                   . 'at the box office.');
}



$_SESSION['last_bookings'] = $confirmed;

redirect('confirmation.php');
