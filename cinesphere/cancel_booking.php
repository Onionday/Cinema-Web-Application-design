<?php
/* =====================================================================
 * cancel_booking.php
 *
 * Cancels one confirmed booking and releases its seats.
 *
 * The booking row is kept - it is UPDATEd to 'cancelled' rather than
 * deleted, so the customer keeps a record of it - while the rows in
 * booking_seats are removed, which is what actually puts the seats back
 * on sale for everybody else.
 *
 * SQL used: SELECT, UPDATE, DELETE.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

require_post();
require_login();

$user_id    = current_user_id();
$booking_id = (int) ($_POST['booking_id'] ?? 0);

/* ---- The booking must exist and belong to this customer ----------- */
$booking = get_booking($booking_id);

if (!$booking || (int) $booking['user_id'] !== $user_id) {
    flash('error', 'That booking was not found on your account.');
    redirect('mybookings.php');
}

if ($booking['booking_status'] !== 'confirmed') {
    flash('info', 'That booking is already '
                . $booking['booking_status'] . '.');
    redirect('mybookings.php?show=other');
}

/* ---- The two-hour cut-off ----------------------------------------- */
$startsAt = strtotime($booking['show_date'] . ' ' . $booking['show_time']);

if ($startsAt - time() <= 2 * 3600) {
    flash('error', 'Bookings can only be cancelled online up to two hours '
                 . 'before the screening. Please speak to the box office on '
                 . '+65 6100 2000.');
    redirect('mybookings.php');
}

/* Capture the seats before they are released, for the email. */
$seats = get_booking_seats($booking_id);

/* ---- Cancel, as one transaction ----------------------------------- */
$conn = db();
$conn->begin_transaction();

try {
    /* UPDATE - the booking is marked cancelled and refunded */
    db_execute(
        "UPDATE bookings
            SET booking_status = 'cancelled',
                payment_status = 'refunded'
          WHERE booking_id = ? AND user_id = ? AND booking_status = 'confirmed'",
        [$booking_id, $user_id]
    );

    /* DELETE - the seats go back on sale */
    db_execute('DELETE FROM booking_seats WHERE booking_id = ?', [$booking_id]);

    $conn->commit();

} catch (Throwable $ex) {
    $conn->rollback();
    flash('error', 'We could not cancel that booking. Please try again, or '
                 . 'call the box office.'
                 . (DEBUG ? ' (' . $ex->getMessage() . ')' : ''));
    redirect('mybookings.php');
}

/* ---- Acknowledge it ------------------------------------------------ */
$booking['payment_status'] = 'refunded';
send_cancellation_notice($booking, $seats);

flash('success', 'Booking ' . $booking['booking_ref'] . ' has been cancelled and '
                . seat_list_text($seats) . ' released. A refund of '
                . money($booking['total_amount']) . ' will be returned to the '
                . 'original payment method, and a confirmation has been emailed.');

redirect('mybookings.php?show=other');
