<?php
/* =====================================================================
 * confirmation.php - the e-tickets for a completed transaction
 *
 * This page is generated entirely on the server from the bookings just
 * written by process_booking.php. The booking ids arrive through the
 * session, and every one of them is re-checked against the signed-in
 * customer before anything is displayed.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';

require_login('Sign in to view your booking confirmation.');

$ids = $_SESSION['last_bookings'] ?? [];

/* A single booking can also be opened from "My bookings". */
if (!$ids && isset($_GET['ref'])) {
    $row = db_one(
        'SELECT booking_id FROM bookings WHERE booking_ref = ? AND user_id = ?',
        [trim((string) $_GET['ref']), current_user_id()]
    );
    if ($row) {
        $ids = [(int) $row['booking_id']];
    }
}

if (!$ids) {
    flash('info', 'There is no confirmation to show. Here is everything you have booked.');
    redirect('mybookings.php');
}

/* ---- Load them, discarding anything that is not this customer's ---- */
$bookings = [];
foreach ($ids as $id) {
    $b = get_booking((int) $id);
    if ($b && (int) $b['user_id'] === current_user_id()) {
        $b['seats'] = get_booking_seats((int) $b['booking_id']);
        $bookings[] = $b;
    }
}

if (!$bookings) {
    flash('error', 'Those bookings could not be found on your account.');
    redirect('mybookings.php');
}

$grand = 0.0;
$seatTally = 0;
foreach ($bookings as $b) {
    $grand += (float) $b['total_amount'];
    $seatTally += (int) $b['seat_count'];
}

$page_title       = 'Booking confirmed';
$page_description = 'Your CineSphere booking confirmation and e-tickets.';

require __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding-bottom:4rem;">

  <p class="breadcrumb">
    <a href="index.php">Home</a><span>/</span>
    <a href="mybookings.php">My bookings</a><span>/</span>Confirmation
  </p>

  <!-- ======================= THE GOOD NEWS ====================== -->
  <div class="confirm-banner" style="margin-top:1.5rem;">
    <span class="tick" aria-hidden="true">&check;</span>
    <div>
      <h1><?= count($bookings) === 1 ? 'Your booking is confirmed'
                                     : 'Your bookings are confirmed' ?></h1>
      <p>
        <?= $seatTally ?> <?= $seatTally === 1 ? 'ticket' : 'tickets' ?>
        across <?= count($bookings) ?>
        <?= count($bookings) === 1 ? 'screening' : 'screenings' ?>,
        <?= e(money($grand)) ?> charged.
        An acknowledgement has been sent to <strong><?= e($bookings[0]['email']) ?></strong>.
      </p>
    </div>
  </div>

  <div class="btn-row" style="margin-bottom:2rem;">
    <button type="button" class="btn btn-primary" id="print-ticket">Print these tickets</button>
    <a class="btn btn-ghost" href="mybookings.php">All my bookings</a>
    <a class="btn btn-ghost" href="showtimes.php">Book something else</a>
  </div>


  <!-- ========================= TICKETS ========================== -->
  <?php foreach ($bookings as $b): ?>
    <article class="ticket">

      <div class="ticket-poster">
        <img src="images/<?= e($b['poster']) ?>"
             alt="Poster for <?= e($b['title']) ?>" width="150" height="225">
      </div>

      <div class="ticket-main">
        <h3><?= e($b['title']) ?> <span class="rating"><?= e($b['classification']) ?></span></h3>
        <p class="muted small" style="margin-bottom:.2rem;">
          <?= e($b['cinema_name']) ?> &middot; <?= e($b['cinema_address']) ?>
        </p>

        <dl class="ticket-facts">
          <div>
            <dt>Date</dt>
            <dd><?= e(pretty_date($b['show_date'])) ?></dd>
          </div>
          <div>
            <dt>Doors / start</dt>
            <dd>
              <?= e(pretty_time(date('H:i:s', strtotime($b['show_time']) - 15 * 60))) ?>
              / <?= e(pretty_time($b['show_time'])) ?>
            </dd>
          </div>
          <div>
            <dt>Hall</dt>
            <dd><?= e($b['hall_name']) ?> &middot; <?= e($b['format']) ?></dd>
          </div>
          <div>
            <dt>Seats</dt>
            <dd class="seats"><?= e(seat_list_text($b['seats'])) ?></dd>
          </div>
          <div>
            <dt>Runtime</dt>
            <dd><?= e(pretty_runtime((int) $b['duration_min'])) ?></dd>
          </div>
          <div>
            <dt>Paid</dt>
            <dd><?= e(money($b['total_amount'])) ?></dd>
          </div>
        </dl>

        <details style="margin-top:1.1rem;">
          <summary class="small muted" style="cursor:pointer;">Seat-by-seat breakdown</summary>
          <div class="table-wrap" style="margin-top:.8rem;">
            <table class="data" style="min-width:auto;">
              <thead>
                <tr>
                  <th scope="col">Seat</th>
                  <th scope="col">Type</th>
                  <th scope="col" class="num">Price</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($b['seats'] as $s): ?>
                  <tr>
                    <td><strong><?= e($s['seat_row'] . $s['seat_number']) ?></strong></td>
                    <td><?= e($s['seat_type']) ?></td>
                    <td class="num"><?= e(money($s['seat_price'])) ?></td>
                  </tr>
                <?php endforeach; ?>
                <tr>
                  <td colspan="2">
                    Booking fee &times; <?= (int) $b['seat_count'] ?>
                  </td>
                  <td class="num"><?= e(money(BOOKING_FEE * (int) $b['seat_count'])) ?></td>
                </tr>
                <tr>
                  <td colspan="2"><strong>Total</strong></td>
                  <td class="num"><strong><?= e(money($b['total_amount'])) ?></strong></td>
                </tr>
              </tbody>
            </table>
          </div>
        </details>
      </div>

      <div class="ticket-stub">
        <span class="small muted">Booking reference</span>
        <span class="ticket-ref"><?= e($b['booking_ref']) ?></span>
        <span class="barcode" data-ref="<?= e($b['booking_ref']) ?>" aria-hidden="true"></span>
        <span class="badge badge-good">Confirmed</span>
        <span class="small faint"><?= (int) $b['seat_count'] ?>
          <?= (int) $b['seat_count'] === 1 ? 'ticket' : 'tickets' ?></span>
      </div>

    </article>
  <?php endforeach; ?>


  <!-- ====================== WHAT HAPPENS NOW ==================== -->
  <div class="card card-pad-lg" style="margin-top:2rem;">
    <h2 style="font-size:1.15rem;">Before you go</h2>
    <div class="grid grid-3" style="gap:1.6rem;">
      <div>
        <h3 style="font-size:.95rem;">Getting in</h3>
        <p class="small muted" style="margin:0;">
          Show the reference at the box office or on your phone at the hall
          door. Doors open fifteen minutes before the advertised start.
        </p>
      </div>
      <div>
        <h3 style="font-size:.95rem;">Changed your mind</h3>
        <p class="small muted" style="margin:0;">
          Cancel from <a href="mybookings.php">My bookings</a> up to two hours
          before the screening and the seats go straight back on sale.
        </p>
      </div>
      <div>
        <h3 style="font-size:.95rem;">Your email</h3>
        <p class="small muted" style="margin:0;">
          <?php if (MAIL_MODE === 'file'): ?>
            For this coursework build the acknowledgement is written to the
            project&rsquo;s <code>mail/</code> folder rather than being sent
            out to the internet.
          <?php else: ?>
            The acknowledgement was handed to the local mail server for
            delivery to <?= e($bookings[0]['email']) ?>.
          <?php endif; ?>
        </p>
      </div>
    </div>
  </div>

</div>

<?php
/* Once the tickets have been rendered the ids are cleared, so a refresh
   does not keep re-announcing a booking that was made minutes ago. */
unset($_SESSION['last_bookings']);

require __DIR__ . '/includes/footer.php';
