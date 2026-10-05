<?php
/* =====================================================================
 * includes/mailer.php
 *
 * Booking acknowledgements.
 *
 * The project guidelines require that no mail leaves the local web
 * server, so MAIL_MODE is 'file' by default: each message is written
 * into the project's mail/ folder as a readable .eml file that can be
 * opened in any mail client or text editor. Switching MAIL_MODE to
 * 'mail' in config.php hands the same message to PHP's mail().
 * ===================================================================== */

require_once __DIR__ . '/functions.php';

/**
 * Sends (or files) one message.
 *
 * @return bool true if the acknowledgement was delivered or written
 */
function send_mail(string $to, string $subject, string $body): bool
{
    $headers = [
        'From: ' . SITE_NAME . ' <' . SITE_EMAIL . '>',
        'Reply-To: ' . SITE_EMAIL,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: CineSphere/1.0 (PHP/' . phpversion() . ')',
    ];

    if (MAIL_MODE === 'mail') {
        return @mail($to, $subject, $body, implode("\r\n", $headers));
    }

    /* ---- file mode ---------------------------------------------- */
    if (!is_dir(MAIL_DIR) && !@mkdir(MAIL_DIR, 0777, true)) {
        return false;
    }

    $name = date('Ymd-His') . '-' . substr(md5($to . $subject . microtime()), 0, 6) . '.eml';
    $eml  = 'Date: ' . date('r') . "\r\n"
          . 'To: ' . $to . "\r\n"
          . 'Subject: ' . $subject . "\r\n"
          . implode("\r\n", $headers) . "\r\n\r\n"
          . $body . "\r\n";

    return (bool) @file_put_contents(MAIL_DIR . DIRECTORY_SEPARATOR . $name, $eml);
}

/**
 * The acknowledgement the theme asks for: sent once the customer has
 * confirmed their final booking selection.
 */
function send_booking_confirmation(array $booking, array $seats): bool
{
    $when  = pretty_date($booking['show_date']) . ' at ' . pretty_time($booking['show_time']);
    $line  = str_repeat('=', 58);
    $tick  = money($booking['total_amount']);

    $body = <<<TXT
{$line}
  CINESPHERE - BOOKING CONFIRMED
{$line}

Hello {$booking['full_name']},

Your booking is confirmed. Please show this reference at the box
office or scan it at the hall entrance.

  Booking reference : {$booking['booking_ref']}
  Film              : {$booking['title']} ({$booking['classification']})
  Format            : {$booking['format']}
  Date and time     : {$when}
  Venue             : {$booking['cinema_name']}, {$booking['cinema_location']}
  Hall              : {$booking['hall_name']} ({$booking['hall_type']})
  Seats             :
TXT;

    $body .= seat_list_text($seats) . "\n";
    $body .= "  Tickets           : " . (int) $booking['seat_count'] . "\n";
    $body .= "  Total paid        : {$tick}\n";
    $body .= "  Payment status    : " . ucfirst($booking['payment_status']) . "\n\n";

    $body .= "Seat breakdown\n";
    $body .= str_repeat('-', 58) . "\n";
    foreach ($seats as $s) {
        $body .= sprintf("  %-6s %-10s %10s\n",
                         $s['seat_row'] . $s['seat_number'],
                         $s['seat_type'],
                         money($s['seat_price']));
    }
    $body .= str_repeat('-', 58) . "\n";
    $body .= sprintf("  %-17s %10s\n\n", 'TOTAL', $tick);

    $body .= "Doors open fifteen minutes before the advertised start time.\n";
    $body .= "Tickets may be cancelled from 'My bookings' up to two hours\n";
    $body .= "before the screening begins.\n\n";
    $body .= "Thank you for booking with " . SITE_NAME . ".\n";
    $body .= $line . "\n";

    $subject = SITE_NAME . ' booking ' . $booking['booking_ref']
             . ' - ' . $booking['title'];

    $ok = send_mail($booking['email'], $subject, $body);

    if ($ok) {
        /* UPDATE - record that the acknowledgement went out */
        db_execute('UPDATE bookings SET email_sent = 1 WHERE booking_id = ?',
                   [(int) $booking['booking_id']]);
    }

    return $ok;
}

/** Acknowledgement for a cancellation. */
function send_cancellation_notice(array $booking, array $seats): bool
{
    $when = pretty_date($booking['show_date']) . ' at ' . pretty_time($booking['show_time']);
    $body = "Hello {$booking['full_name']},\n\n"
          . "Booking {$booking['booking_ref']} has been cancelled.\n\n"
          . "  Film   : {$booking['title']}\n"
          . "  When   : {$when}\n"
          . "  Seats  : " . seat_list_text($seats) . "\n"
          . "  Refund : " . money($booking['total_amount'])
          . " to the original payment method\n\n"
          . "Those seats have been released and are available to other\n"
          . "customers again. Refunds usually appear within five working days.\n\n"
          . "- " . SITE_NAME . "\n";

    return send_mail(
        $booking['email'],
        SITE_NAME . ' booking ' . $booking['booking_ref'] . ' cancelled',
        $body
    );
}

/** Acknowledgement that a feedback form was received. */
function send_contact_ack(string $name, string $to, string $subject_line, int $ref): bool
{
    $body = "Hello {$name},\n\n"
          . "Thank you for contacting " . SITE_NAME . ". Your message has been\n"
          . "logged as reference #CM{$ref} under the subject \"{$subject_line}\".\n\n"
          . "A member of the guest services team will reply within two\n"
          . "working days.\n\n"
          . "- " . SITE_NAME . "\n";

    return send_mail($to, SITE_NAME . ' - we received your message (#CM' . $ref . ')', $body);
}
