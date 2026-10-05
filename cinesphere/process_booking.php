<?php
/* =====================================================================
 * process_booking.php
 *
 * The final selection step. Takes the entries the customer ticked on
 * preferences.php, re-checks each one against the live database, asks
 * the (simulated) payment gateway for an answer, and on approval turns
 * each surviving entry into a real booking.
 *
 * Each booking is written inside its own transaction, so if the very
 * last seat of one entry is sold a fraction of a second earlier, only
 * that entry fails - the customer's other confirmed bookings stand.
 *
 * SQL used: SELECT, INSERT, UPDATE, DELETE.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

require_post();
require_login();

$user_id = current_user_id();
$me      = current_user();

/* ---- What did they tick? ------------------------------------------ */
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

/* ---- Re-read every ticked entry, in the customer's own ranking ----- */
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

/* Build the full picture for each, using the same availability logic
   the short-list page displayed. */
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

if (!$toBook) {
    flash('error', 'None of the entries you selected are still available. '
                 . 'The seats were taken while you were deciding.');
    redirect('preferences.php');
}

$grandTotal = 0.0;
foreach ($toBook as $p) {
    $grandTotal += (float) $p['est_total'];
}

/* =====================================================================
 *  The payment gateway
 *
 *  A real build would redirect to the provider here and come back to a
 *  callback URL. The project brief says payment is assumed to be handled
 *  by a third party and the redirection need not be implemented, so this
 *  stands in for it - what matters is that both answers are handled.
 * ================================================================== */
$authCode = strtoupper(bin2hex(random_bytes(4)));

if ($gateway === 'declined') {

    /* Log the failed attempt against each entry so it is auditable, but
       do not touch booking_seats: no seat is ever held by a failed
       payment, and nothing leaves the short-list. */
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

/* =====================================================================
 *  Payment approved - turn each entry into a booking
 * ================================================================== */
$conn      = db();
$confirmed = [];
$lost      = [];

foreach ($toBook as $p) {

    $conn->begin_transaction();

    try {
        /* Last-moment re-check inside the transaction. */
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

        /* INSERT - the booking itself */
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

        /* INSERT - the seats. The UNIQUE key on (showtime_id, seat_id)
           is the real guard against a double sale: if another customer
           committed the same seat a moment ago this throws, and the
           whole booking rolls back. */
        foreach ($p['seats'] as $s) {
            db_execute(
                'INSERT INTO booking_seats (booking_id, showtime_id, seat_id, seat_price)
                 VALUES (?, ?, ?, ?)',
                [$booking_id, (int) $p['showtime_id'], (int) $s['seat_id'],
                 (float) $s['seat_price']],
                'iiid'
            );
        }

        /* DELETE - the entry has become a booking, so it leaves the
           short-list. */
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

/* ---- Tidy the ranking that is left --------------------------------- */
$left = db_all('SELECT pref_id FROM preferences WHERE user_id = ? ORDER BY rank_order, pref_id',
               [$user_id]);
$n = 1;
foreach ($left as $r) {
    db_execute('UPDATE preferences SET rank_order = ? WHERE pref_id = ?',
               [$n++, (int) $r['pref_id']]);
}

/* ---- Email the acknowledgement for each confirmed booking ---------- */
$mailed = 0;
foreach ($confirmed as $bid) {
    $booking = get_booking($bid);
    if ($booking && send_booking_confirmation($booking, get_booking_seats($bid))) {
        $mailed++;
    }
}

/* ---- Report back --------------------------------------------------- */
if (!$confirmed) {
    flash('error', 'Payment was approved but every seat had just been sold. '
                 . 'Nothing has been charged. Please choose different seats.');
    redirect('preferences.php');
}

if ($lost) {
    $names = implode(', ', array_column($lost, 'title'));
    flash('warning', 'We could not complete ' . count($lost) . ' of your selections ('
                   . $names . ') because the seats went in the last few seconds. '
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
                   . ' been sent to ' . $me['email']
                   . (MAIL_MODE === 'file'
                      ? ' (written to the project\'s mail folder).' : '.'));
} else {
    flash('warning', 'Your booking is confirmed, but the acknowledgement email '
                   . 'could not be sent. Your reference below is all you need '
                   . 'at the box office.');
}

/* Hand the ids to the confirmation page through the session rather than
   the URL, so a reference cannot be guessed by editing the address bar. */
$_SESSION['last_bookings'] = $confirmed;

redirect('confirmation.php');
