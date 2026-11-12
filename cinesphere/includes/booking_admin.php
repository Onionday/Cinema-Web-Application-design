<?php
























require_once __DIR__ . '/functions.php';






function find_booking_by_ref(string $ref): ?array
{
    $row = db_one('SELECT booking_id FROM bookings WHERE booking_ref = ?', [$ref]);
    return $row ? get_booking((int) $row['booking_id']) : null;
}


function get_free_seats(int $showtime_id, int $hall_id): array
{
    $taken = get_taken_seat_ids($showtime_id);
    return array_values(array_filter(
        get_hall_seats($hall_id),
        static fn($s) => !isset($taken[(int) $s['seat_id']])
    ));
}


function get_booking_adjustments(int $booking_id): array
{
    return db_all(
        'SELECT a.*, u.full_name AS staff_name
           FROM booking_adjustments a
           LEFT JOIN users u ON u.user_id = a.staff_id
          WHERE a.booking_id = ?
          ORDER BY a.created_at DESC, a.adjustment_id DESC',
        [$booking_id]
    );
}






function v_booking_ref(string $raw): string
{
    $ref = strtoupper(trim($raw));
    return preg_match('/^[A-Z0-9]{4,16}$/', $ref) ? $ref : '';
}


function v_reason(string $raw, array &$errors, string $field = 'reason'): string
{
    $reason = trim(preg_replace('/\s+/', ' ', $raw));
    $len = mb_strlen($reason);
    if ($len < 5) {
        $errors[$field] = 'Give a reason of at least 5 characters (it is shown in the change log).';
    } elseif ($len > 160) {
        $errors[$field] = 'Keep the reason to 160 characters or fewer.';
    }
    return $reason;
}









function lock_changeable_booking(int $booking_id): array
{
    $booking = get_booking($booking_id);
    if (!$booking) {
        throw new RuntimeException('That booking no longer exists.');
    }


    $screening = db_one('SELECT showtime_id, show_date, show_time FROM showtimes WHERE showtime_id = ? FOR UPDATE',
                        [(int) $booking['showtime_id']]);
    $fresh = db_one('SELECT * FROM bookings WHERE booking_id = ? FOR UPDATE',
                    [$booking_id]);

    if (!$fresh || $fresh['booking_status'] !== 'confirmed') {
        throw new RuntimeException('Only confirmed bookings can be changed. This one is '
                                 . ($fresh['booking_status'] ?? 'missing') . '.');
    }

    $booking = array_replace($booking, $fresh, $screening);
    if (showtime_has_passed($booking)) {
        throw new RuntimeException('This screening has already started, so the booking can no longer be changed.');
    }
    return $booking;
}

function log_adjustment(int $booking_id, int $staff_id, string $action,
                        string $detail, float $amount, string $reason): void
{

    db_execute(
        'INSERT INTO booking_adjustments
                (booking_id, staff_id, action, detail, amount_change, reason)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$booking_id, $staff_id, $action, mb_substr($detail, 0, 255), round($amount, 2), $reason],
        'iissds'
    );
}






function cancel_locked_booking(array $booking, array $seats, string $reason, int $staff_id): array
{
    $booking_id = (int)$booking['booking_id'];
    archive_booking_seats($booking_id);
    db_execute("UPDATE bookings SET booking_status = 'cancelled', payment_status = 'refunded'
                 WHERE booking_id = ?", [$booking_id]);
    db_execute('DELETE FROM booking_seats WHERE booking_id = ?', [$booking_id]);
    $refund = (float)$booking['total_amount'];
    log_adjustment($booking_id, $staff_id, 'booking_cancel',
                   'Cancelled by staff; released ' . seat_list_text($seats), -$refund, $reason);
    $booking['booking_status'] = 'cancelled';
    $booking['payment_status'] = 'refunded';
    return ['refund'=>$refund, 'seats'=>seat_list_text($seats), 'cancelled'=>true,
            'booking'=>$booking, 'seat_rows'=>$seats];
}


function staff_cancel_booking(int $booking_id, string $reason, int $staff_id, ?array &$notice = null): float
{
    $conn = db();
    $conn->begin_transaction();
    try {
        $booking = lock_changeable_booking($booking_id);
        $result = cancel_locked_booking($booking, get_held_seats($booking_id, true), $reason, $staff_id);
        $conn->commit();
        $notice = $result;
        return $result['refund'];
    } catch (Throwable $ex) {
        $conn->rollback();
        throw $ex;
    }
}







function staff_refund_seats(int $booking_id, array $seat_ids, string $reason, int $staff_id): array
{
    $seat_ids = array_values(array_unique(array_map('intval', $seat_ids)));
    if (!$seat_ids) {
        throw new RuntimeException('Tick at least one seat to refund.');
    }

    $conn = db();
    $conn->begin_transaction();
    try {
        $booking = lock_changeable_booking($booking_id);

        $held   = get_held_seats($booking_id, true);
        $byId   = [];
        foreach ($held as $s) {
            $byId[(int) $s['seat_id']] = $s;
        }
        $chosen = [];
        foreach ($seat_ids as $id) {
            if (!isset($byId[$id])) {
                throw new RuntimeException('One of those seats is not part of this booking any more.');
            }
            $chosen[] = $byId[$id];
        }

        if (count($chosen) === count($held)) {
            $result = cancel_locked_booking($booking, $held, $reason, $staff_id);
            $conn->commit();
            return $result;
        }


        $refund = 0.0;
        foreach ($chosen as $s) {
            $refund += (float) $s['seat_price'] + BOOKING_FEE;
        }
        $refund = round($refund, 2);

        $marks = implode(',', array_fill(0, count($seat_ids), '?'));


        db_execute("DELETE FROM booking_seats WHERE booking_id = ? AND seat_id IN ($marks)",
                   array_merge([$booking_id], $seat_ids));
        db_execute("DELETE FROM booking_seat_history WHERE booking_id = ? AND seat_id IN ($marks)",
                   array_merge([$booking_id], $seat_ids));


        db_execute('UPDATE bookings
                       SET seat_count = seat_count - ?, total_amount = total_amount - ?
                     WHERE booking_id = ?',
                   [count($chosen), $refund, $booking_id], 'idi');

        archive_booking_seats($booking_id);

        $text = seat_list_text($chosen);
        log_adjustment($booking_id, $staff_id, 'seat_refund',
                       'Refunded ' . count($chosen) . ' of ' . count($held) . ' seats: ' . $text,
                       -$refund, $reason);
        $booking['seat_count'] = (int)$booking['seat_count'] - count($chosen);
        $booking['total_amount'] = round((float)$booking['total_amount'] - $refund, 2);
        $remaining = get_held_seats($booking_id, true);
        $conn->commit();
        return ['refund'=>$refund, 'seats'=>$text, 'cancelled'=>false,
                'booking'=>$booking, 'seat_rows'=>$remaining];
    } catch (Throwable $ex) {
        $conn->rollback();
        throw $ex;
    }
}








function staff_move_seat(int $booking_id, int $from_seat, int $to_seat,
                         string $reason, int $staff_id, string $upgrade_payment = ''): array
{
    if ($from_seat === $to_seat) {
        throw new RuntimeException('Choose a different seat to move to.');
    }

    $conn = db();
    $conn->begin_transaction();
    try {
        $booking = lock_changeable_booking($booking_id);
        $showtime_id = (int) $booking['showtime_id'];
        $st = get_showtime($showtime_id);

        $from = null;
        foreach (get_held_seats($booking_id, true) as $s) {
            if ((int) $s['seat_id'] === $from_seat) {
                $from = $s;
            }
        }
        if (!$from) {
            throw new RuntimeException('That seat is not part of this booking any more.');
        }

        $to = db_one('SELECT seat_id, seat_row, seat_number, seat_type, surcharge
                        FROM seats WHERE seat_id = ? AND hall_id = ?',
                     [$to_seat, (int) $st['hall_id']]);
        if (!$to) {
            throw new RuntimeException('The new seat must be in the same hall.');
        }
        $taken = get_taken_seat_ids($showtime_id);
        if (isset($taken[$to_seat])) {
            throw new RuntimeException('Seat ' . $to['seat_row'] . $to['seat_number']
                                     . ' has just been sold. Choose another.');
        }

        $newPrice   = round((float) $from['seat_price'] - (float) $from['surcharge']
                            + (float) $to['surcharge'], 2);
        $difference = round($newPrice - (float) $from['seat_price'], 2);

        if ($difference > 0 && $upgrade_payment !== 'success') {
            throw new RuntimeException($upgrade_payment === 'declined'
                ? 'The additional payment was declined. The seat and booking are unchanged.'
                : 'Approve the simulated additional payment before upgrading this seat. Nothing was changed.');
        }



        db_execute('UPDATE booking_seats SET seat_id = ?, seat_price = ?
                     WHERE booking_id = ? AND seat_id = ?',
                   [$to_seat, $newPrice, $booking_id, $from_seat], 'idii');


        db_execute('DELETE FROM booking_seat_history WHERE booking_id = ? AND seat_id = ?',
                   [$booking_id, $from_seat]);
        archive_booking_seats($booking_id);

        if ($difference != 0.0) {
            db_execute('UPDATE bookings SET total_amount = total_amount + ? WHERE booking_id = ?',
                       [$difference, $booking_id], 'di');
        }

        $fromText = $from['seat_row'] . $from['seat_number'];
        $toText   = $to['seat_row'] . $to['seat_number'];
        log_adjustment($booking_id, $staff_id, 'seat_change',
                       "Moved {$fromText} ({$from['seat_type']}) to {$toText} ({$to['seat_type']})"
                       . ($difference > 0 ? '; simulated additional payment approved' : ""),
                       $difference, $reason);
        $booking['total_amount'] = round((float)$booking['total_amount'] + $difference, 2);
        $currentSeats = get_held_seats($booking_id, true);
        $conn->commit();
        return ['from'=>$fromText, 'to'=>$toText, 'difference'=>$difference,
                'booking'=>$booking, 'seat_rows'=>$currentSeats];
    } catch (Throwable $ex) {
        $conn->rollback();
        throw $ex;
    }
}












function set_showtime_price(int $showtime_id, float $price): array
{
    $conn = db();
    $conn->begin_transaction();
    try {
        $st = db_one('SELECT showtime_id, show_date, show_time, base_price, status
                        FROM showtimes WHERE showtime_id = ? FOR UPDATE', [$showtime_id]);
        if (!$st) {
            throw new RuntimeException('That screening does not exist.');
        }
        if ($st['status'] === 'cancelled') {
            throw new RuntimeException('Reopen the screening before changing its price.');
        }
        if (showtime_has_passed($st)) {
            throw new RuntimeException('That screening has already started.');
        }


        db_execute('UPDATE showtimes SET base_price = ? WHERE showtime_id = ?',
                   [$price, $showtime_id], 'di');


        $effective = showtime_price(['base_price' => $price, 'show_date' => $st['show_date']]);


        db_execute('UPDATE preference_seats ps
                      JOIN preferences p ON p.pref_id = ps.pref_id
                      JOIN seats s       ON s.seat_id = ps.seat_id
                       SET ps.seat_price = ? + s.surcharge
                     WHERE p.showtime_id = ?',
                   [$effective, $showtime_id], 'di');


        $res = db_execute('UPDATE preferences p
                              SET p.est_total = (SELECT COALESCE(SUM(ps.seat_price), 0)
                                                        + ? * COUNT(ps.pref_seat_id)
                                                   FROM preference_seats ps
                                                  WHERE ps.pref_id = p.pref_id)
                            WHERE p.showtime_id = ?',
                          [BOOKING_FEE, $showtime_id], 'di');

        $conn->commit();
        return ['old' => (float) $st['base_price'], 'new' => $price,
                'repriced' => (int) $res['affected']];
    } catch (Throwable $ex) {
        $conn->rollback();
        throw $ex;
    }
}
